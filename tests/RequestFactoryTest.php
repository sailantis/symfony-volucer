<?php

declare(strict_types=1);

namespace Sailantis\SymfonyVolucer\Tests;

use PHPUnit\Framework\TestCase;
use Sailantis\SymfonyVolucer\RequestFactory;
use Volucer\Request as VolucerRequest;

/**
 * What the bridge builds, and the three fields a worker cannot copy but must reconstruct.
 *
 * Each of these is a way a request can look right and behave wrongly: an empty `$request->request`
 * because the body was never parsed, a `null` client IP because `REMOTE_ADDR` was never set, or a
 * redirect to port 80 on a TLS deployment because the port was assumed.
 */
final class RequestFactoryTest extends TestCase
{
    private RequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RequestFactory();
    }

    private static function request(array $overrides = []): VolucerRequest
    {
        return new VolucerRequest(
            method: $overrides['method'] ?? 'GET',
            uri: $overrides['uri'] ?? '/',
            protocol: $overrides['protocol'] ?? 'HTTP/1.1',
            headers: $overrides['headers'] ?? [],
            remoteAddr: $overrides['remoteAddr'] ?? '127.0.0.1',
            scriptFilename: $overrides['scriptFilename'] ?? '/srv/app/public/index.php',
            https: $overrides['https'] ?? false,
            body: $overrides['body'] ?? '',
        );
    }

    public function test_the_request_line_and_server_variables(): void
    {
        $symfony = $this->factory->create(self::request([
            'method' => 'POST',
            'uri' => '/submit?page=2',
            'headers' => [
                'Host' => 'example.test:8443',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Content-Length' => '7',
                'Accept' => 'application/json',
                'X-Forwarded-For' => '10.0.0.1',
            ],
            'body' => 'a=1&b=2',
        ]));

        self::assertSame('POST', $symfony->getMethod());
        self::assertSame('/submit', $symfony->getPathInfo());
        self::assertSame('page=2', $symfony->getQueryString());
        self::assertSame('example.test', $symfony->getHost());
        self::assertSame(8443, $symfony->getPort());
        self::assertSame('application/json', $symfony->headers->get('Accept'));
        self::assertSame('127.0.0.1', $symfony->server->get('REMOTE_ADDR'));
    }

    public function test_a_url_encoded_body_reaches_request(): void
    {
        $symfony = $this->factory->create(self::request([
            'method' => 'POST',
            'uri' => '/submit',
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => 'a=1&b=two&list%5B%5D=x',
        ]));

        self::assertSame(
            ['a' => '1', 'b' => 'two', 'list' => ['x']],
            $symfony->request->all(),
            'a SAPI populates $_POST for this media type, so the bridge must too',
        );
    }

    public function test_a_json_body_is_not_parsed_into_request(): void
    {
        // PHP does not populate `$_POST` for JSON, and an application reads `getContent()`.
        // Parsing it here would make a worker behave differently from the deployment the
        // application was tested on.
        $symfony = $this->factory->create(self::request([
            'method' => 'POST',
            'uri' => '/api',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"a":1}',
        ]));

        self::assertSame([], $symfony->request->all());
        self::assertSame('{"a":1}', $symfony->getContent());
    }

    public function test_a_multipart_body_is_deliberately_not_parsed(): void
    {
        $symfony = $this->factory->create(self::request([
            'method' => 'POST',
            'uri' => '/upload',
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=x'],
            'body' => "--x\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n--x--\r\n",
        ]));

        self::assertSame([], $symfony->request->all());
        self::assertSame(
            [],
            $symfony->files->all(),
            'a half-parsed upload would be worse than an empty one, so none is invented',
        );
    }

    public function test_the_scheme_and_port_come_from_the_frame(): void
    {
        // The worker has no socket, so `https` is the only evidence there is. A redirect
        // generated from the wrong scheme is a link to nothing.
        $secure = $this->factory->create(self::request([
            'uri' => '/',
            'headers' => ['Host' => 'example.test'],
            'https' => true,
        ]));

        self::assertSame('https', $secure->getScheme());
        self::assertSame(443, $secure->getPort());

        $plain = $this->factory->create(self::request(['uri' => '/', 'headers' => ['Host' => 'example.test']]));
        self::assertSame('http', $plain->getScheme());
        self::assertSame(80, $plain->getPort());
    }

    public function test_cookies_reach_the_request(): void
    {
        $symfony = $this->factory->create(self::request([
            'uri' => '/',
            'headers' => ['Host' => 'example.test', 'Cookie' => 'PHPSESSID=abc123; theme=dark'],
        ]));

        self::assertSame('abc123', $symfony->cookies->get('PHPSESSID'));
        self::assertSame('dark', $symfony->cookies->get('theme'));
    }

    public function test_a_repeated_header_arrives_joined_from_the_sdk(): void
    {
        // The SDK collapses repeated request headers into one comma-joined value, because that
        // is what a repeated HTTP field means. The bridge copies that map, so the assertion is
        // that it survives rather than that the bridge re-joins anything.
        $symfony = $this->factory->create(self::request([
            'uri' => '/',
            'headers' => ['Host' => 'example.test', 'X-Thing' => 'a, b'],
        ]));

        self::assertSame('a, b', $symfony->headers->get('X-Thing'));
    }

    public function test_an_ipv6_host_header_keeps_its_brackets(): void
    {
        self::assertSame(['[::1]', '8080'], RequestFactory::splitHost('[::1]:8080', false));
        self::assertSame(['[::1]', '80'], RequestFactory::splitHost('[::1]', false));
        self::assertSame(['example.test', '443'], RequestFactory::splitHost('example.test', true));
        self::assertSame(['localhost', '80'], RequestFactory::splitHost(null, false));
    }

    public function test_a_response_becomes_a_volucer_response_with_every_header(): void
    {
        $response = new \Symfony\Component\HttpFoundation\Response('hello', 201, [
            'Content-Type' => 'text/plain',
        ]);
        // Two cookies are two headers; `all()` is what keeps them apart.
        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('a', '1'));
        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('b', '2'));

        $volucer = RequestFactory::toVolucerResponse($response);

        self::assertSame(201, $volucer->status);
        self::assertSame('hello', $volucer->body);

        $setCookies = array_values(array_filter(
            $volucer->headers,
            static fn (array $pair): bool => strcasecmp($pair[0], 'Set-Cookie') === 0,
        ));
        self::assertCount(2, $setCookies, 'two cookies must stay two headers');
    }

    public function test_a_trusted_proxy_makes_the_forwarded_address_visible(): void
    {
        // The safety property is the negative case: with no trust list the header is ignored,
        // which is what stops a client from choosing its own address.
        $request = self::request([
            'uri' => '/',
            'remoteAddr' => '10.1.2.3',
            'headers' => ['X-Forwarded-For' => '203.0.113.9'],
        ]);

        $untrusted = $this->factory->create($request);
        self::assertSame('10.1.2.3', $untrusted->getClientIp());

        $trusted = $this->factory->create($request, ['10.1.2.3']);
        self::assertSame('203.0.113.9', $trusted->getClientIp());
    }
}
