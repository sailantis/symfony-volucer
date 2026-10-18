<?php

declare(strict_types=1);

namespace Sailantis\SymfonyVolucer;

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Volucer\Request as VolucerRequest;
use Volucer\Response as VolucerResponse;

/**
 * Translate one volucer request into a Symfony {@see SymfonyRequest}.
 *
 * This is the half that is easy to get wrong and impossible to notice, because a mistake here
 * produces a request that *looks* right: a Symfony request built with fewer superglobals than
 * FPM would set is one where `$request->request`, `$request->cookies` or `$request->files` are
 * silently empty, and the application reads that as "the client sent nothing".
 *
 * ## What a worker does not have, and what is therefore copied
 *
 * Under a SAPI Symfony reads `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE` and `$_FILES` to build
 * the request. A worker has none of them: the volucer request carries a method, a URI, headers
 * and an already-read body, and nothing else. So everything except the body is *reconstructed*
 * from those four facts, and the three that need care are:
 *
 * - **`$_POST`** is only parsed for the media types a PHP SAPI parses, which is why
 *   {@see SymfonyRequest::createFromGlobals} is not used and the parsing is done by the same
 *   `Request::create` machinery Symfony uses for a real request line.
 * - **`REMOTE_ADDR`** has to be stated, or Symfony's trusted-proxy machinery sees `null` and
 *   any `X-Forwarded-For` is dropped — the same defect the server's own trusted-subnet work
 *   fixed for the access log.
 * - **`SERVER_PORT`** is derived from the `Host` header rather than assumed, because a redirect
 *   generated from a wrong port is a link to nothing.
 */
final class RequestFactory
{
    /**
     * Build the Symfony request.
     *
     * `$trustedProxies` is passed straight to `Request::setTrustedProxies()`; `null` means "do
     * not configure", which is the safe default: a worker has no socket, so it cannot tell a
     * proxy from a client and must not guess.
     *
     * @param list<string>|null $trustedProxies
     */
    public function create(VolucerRequest $request, ?array $trustedProxies = null): SymfonyRequest
    {
        [$host, $port] = self::splitHost($request->header('Host'), $request->https);
        $scheme = $request->https ? 'https' : 'http';

        $server = [
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => $request->uri,
            'QUERY_STRING' => self::queryString($request->uri),
            'SERVER_PROTOCOL' => $request->protocol,
            'SERVER_NAME' => $host,
            'SERVER_PORT' => $port,
            'HTTP_HOST' => $request->header('Host') ?? $host,
            'REMOTE_ADDR' => $request->remoteAddr,
            'SCRIPT_NAME' => '',
            'SCRIPT_FILENAME' => $request->scriptFilename,
            'HTTPS' => $request->https ? 'on' : 'off',
        ];

        foreach ($request->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', (string) $name));

            // `Host` is already in `$server` as `HTTP_HOST`, resolved once above. Joining it
            // again would produce `example.test:8443, example.test`, which Symfony refuses as
            // a suspicious host — and would be a wrong header even if it accepted it.
            if ($key === 'HOST') {
                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $server[$key] = (string) $value;
                continue;
            }
            // A header may appear twice and the SDK keeps both; joining with a comma is what
            // HTTP says a repeated field means, and it is what a SAPI does.
            $server['HTTP_' . $key] = isset($server['HTTP_' . $key])
                ? $server['HTTP_' . $key] . ', ' . $value
                : (string) $value;
        }

        // `Request::create` takes the URI and the server array and does the rest — including
        // leaving `cookies` empty, because under a SAPI PHP has already filled `$_COOKIE` from
        // the header. A worker has no `$_COOKIE`, so the header is parsed here; without this an
        // application sees every request as the first one a client ever made.
        $symfony = SymfonyRequest::create(
            $request->uri,
            $request->method,
            [],
            self::parseCookies($request->header('Cookie') ?? ''),
            [],
            $server,
            $request->body,
        );

        // `create()` does not parse a body, because under a SAPI PHP has already read it. A
        // worker's body is bytes on the wire, so the two media types Symfony itself parses are
        // parsed here — the same rule `Request::createFromGlobals` follows, reached from the
        // other direction.
        self::parseBody($symfony, $request);

        if ($trustedProxies !== null) {
            SymfonyRequest::setTrustedProxies($trustedProxies, SymfonyRequest::HEADER_X_FORWARDED_FOR);
        }

        return $symfony;
    }

    /**
     * Populate `$request->request` from the body.
     *
     * Only `application/x-www-form-urlencoded` is parsed. `multipart/form-data` is deliberately
     * **not**: the server has already consumed the request, so the boundary is the only thing
     * that could be parsed from, and a half-parsed upload is worse than an empty one — the
     * application sees no files rather than a truncated set. A worker deployment that needs
     * uploads either uses `upstream` or reads `php://input` itself, and both are legible
     * choices; silently inventing `$_FILES` entries from a body nobody parsed is not.
     */
    private static function parseBody(SymfonyRequest $symfony, VolucerRequest $request): void
    {
        if ($request->body === '') {
            return;
        }

        $contentType = strtolower(trim(explode(';', $request->contentType() ?? '')[0]));

        if ($contentType === 'application/x-www-form-urlencoded') {
            $symfony->request->replace(self::parseUrlEncoded($request->body));
        }
    }

    /**
     * `parse_str` without its variable-name mangling.
     *
     * `parse_str` is what a SAPI uses, and it rewrites `.` and space to `_` in key names — a
     * behaviour PHP documents as a legacy accident. Matching it is deliberate: a bridge that
     * parsed better than PHP would make a worker behave differently from the FPM deployment the
     * application was tested on. `Request::create` is not used here because it needs a request
     * line, and this is a body.
     *
     * @return array<string, mixed>
     */
    private static function parseUrlEncoded(string $body): array
    {
        $parsed = [];
        parse_str($body, $parsed);

        return $parsed;
    }

    /**
     * Parse a `Cookie` header into a parameter bag's worth of values.
     *
     * PHP's own rule, which is what a SAPI does: names and values are URI-decoded, a pair
     * without `=` is skipped, and later duplicates win. Matching PHP rather than doing something
     * tidier is deliberate — a bridge that parsed cookies differently would make a worker behave
     * unlike the FPM deployment the application was tested on.
     *
     * @return array<string, string>
     */
    private static function parseCookies(string $header): array
    {
        $cookies = [];

        foreach (explode(';', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }

            $at = strpos($pair, '=');
            if ($at === false) {
                continue;
            }

            $name = urldecode(substr($pair, 0, $at));
            if ($name === '') {
                continue;
            }

            $cookies[$name] = urldecode(substr($pair, $at + 1));
        }

        return $cookies;
    }

    /**
     * Split a `Host` header into a name and a port.
     *
     * A bracketed IPv6 literal keeps its brackets and its colons; everything else splits on the
     * last colon, which is the only unambiguous rule a host header has.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitHost(?string $host, bool $https): array
    {
        $default = $https ? '443' : '80';

        if ($host === null || trim($host) === '') {
            return ['localhost', $default];
        }

        $host = trim($host);

        if (str_starts_with($host, '[')) {
            $closing = strpos($host, ']');
            if ($closing !== false) {
                $name = substr($host, 0, $closing + 1);
                $rest = substr($host, $closing + 1);

                return str_starts_with($rest, ':') && strlen($rest) > 1
                    ? [$name, substr($rest, 1)]
                    : [$name, $default];
            }

            return [$host, $default];
        }

        if (str_contains($host, ':')) {
            $at = strrpos($host, ':');
            $name = substr($host, 0, $at);
            $port = substr($host, $at + 1);
            if ($name !== '' && $port !== '') {
                return [$name, $port];
            }
        }

        return [$host, $default];
    }

    /**
     * The query string of a request target, or an empty string.
     */
    private static function queryString(string $uri): string
    {
        $at = strpos($uri, '?');

        return $at === false ? '' : substr($uri, $at + 1);
    }

    /**
     * Convert a Symfony response into the SDK's.
     *
     * `Response::send()` is deliberately not called: it emits headers with `header()` and the
     * body with `echo`, and in a worker both write into the SDK's capture buffer rather than to
     * a client. The response is read through its getters and handed over as a value.
     */
    public static function toVolucerResponse(
        \Symfony\Component\HttpFoundation\Response $response
    ): VolucerResponse {
        // `headers->all()` returns `list<string>` per name, which is what the wire wants: two
        // `Set-Cookie` headers are two cookies, and joining them would silently break a session.
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = [(string) $name, (string) $value];
            }
        }

        $body = $response->getContent();
        if ($body === false) {
            // A `StreamedResponse` has no content of its own. Reading the stream is the only
            // honest thing to do, because the protocol declares `body_len` before the body and a
            // response that streams cannot be sent at all — see the README.
            ob_start();
            $response->sendContent();
            $body = (string) ob_get_clean();
        }

        return new VolucerResponse($response->getStatusCode(), $headers, (string) $body);
    }
}
