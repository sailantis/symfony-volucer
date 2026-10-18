<?php

declare(strict_types=1);

namespace Sailantis\SymfonyVolucer\Tests;

use PHPUnit\Framework\TestCase;
use Sailantis\SymfonyVolucer\WorkerRunner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Volucer\Request as VolucerRequest;

/**
 * The reset, which is the acceptance criterion rather than "it serves a request".
 *
 * The bar the plan sets is **two consecutive requests that must not see each other's state**.
 * Each test here is one way state survives a naive bridge.
 */
final class WorkerRunnerTest extends TestCase
{
    private static function request(array $overrides = []): VolucerRequest
    {
        return new VolucerRequest(
            method: $overrides['method'] ?? 'GET',
            uri: $overrides['uri'] ?? '/',
            protocol: $overrides['protocol'] ?? 'HTTP/1.1',
            headers: $overrides['headers'] ?? ['Host' => 'example.test'],
            remoteAddr: $overrides['remoteAddr'] ?? '127.0.0.1',
            scriptFilename: $overrides['scriptFilename'] ?? '/srv/app/public/index.php',
            https: $overrides['https'] ?? false,
            body: $overrides['body'] ?? '',
        );
    }

    public function test_one_request_reaches_the_kernel_and_comes_back(): void
    {
        $kernel = new TestKernel(EchoHandler::response(...));
        $runner = new WorkerRunner($kernel);

        $response = $runner->dispatch(self::request([
            'method' => 'POST',
            'uri' => '/items?id=7',
            'headers' => [
                'Host' => 'example.test',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => 'name=widget',
        ]));

        self::assertSame(200, $response->status);
        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('POST', $payload['method']);
        self::assertSame('/items', $payload['path']);
        self::assertSame(['id' => '7'], $payload['query']);
        self::assertSame(['name' => 'widget'], $payload['post']);
        self::assertSame('example.test', $payload['host']);
        self::assertSame(80, $payload['port']);
    }

    public function test_two_requests_do_not_see_each_others_state(): void
    {
        // The kernel is the same object for both, which is the whole point of a worker: the
        // bridge must reset what the framework leaves behind rather than the process doing it.
        $kernel = new TestKernel(static function (Request $request): Response {
            return new JsonResponse([
                'path' => $request->getPathInfo(),
                'query' => $request->query->all(),
                'post' => $request->request->all(),
                'cookies' => $request->cookies->all(),
                'server_cookie' => $_COOKIE,
                'server_get' => $_GET,
                'server_post' => $_POST,
            ]);
        });
        $runner = new WorkerRunner($kernel);

        $first = json_decode(
            $runner->dispatch(self::request([
                'uri' => '/first?marker=one',
                'method' => 'POST',
                'headers' => [
                    'Host' => 'example.test',
                    'Cookie' => 'a=1',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => 'posted=1',
            ]))->body,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(['marker' => 'one'], $first['query']);
        self::assertSame(['posted' => '1'], $first['post']);
        self::assertSame(['a' => '1'], $first['cookies']);
        self::assertSame(['a' => '1'], $first['server_cookie']);

        $second = json_decode(
            $runner->dispatch(self::request(['uri' => '/second']))->body,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('/second', $second['path']);
        self::assertSame([], $second['query'], 'the second request must not inherit the first query');
        self::assertSame([], $second['post'], 'nor its form body');
        self::assertSame([], $second['cookies'], 'nor its cookies');
        self::assertSame([], $second['server_cookie'], 'nor PHP\'s own superglobal');
        self::assertSame([], $second['server_get']);
        self::assertSame([], $second['server_post']);
    }

    public function test_terminate_runs_after_the_response_is_read(): void
    {
        // `terminate` is where a SAPI would have sent the response, so calling it before the
        // body is read would let a listener change a response the protocol has already declared
        // the length of.
        $kernel = new TestKernel(static fn (): Response => new Response('ok'));
        $runner = new WorkerRunner($kernel);

        $runner->dispatch(self::request(['uri' => '/first']));

        self::assertSame(['/first'], $kernel->terminated);
    }

    public function test_run_resets_state_around_a_throwing_kernel_and_keeps_serving(): void
    {
        // The guarantee is on `run()`, not on `dispatch()`: one bad request must not cost the
        // worker, and the request after it must not see what the failed one left behind. This
        // drives a real channel so both halves are asserted rather than inferred.
        $calls = 0;
        $kernel = new TestKernel(static function (Request $request) use (&$calls): Response {
            $calls++;
            $seen = [$_COOKIE, $_GET, $_POST];

            if ($request->getPathInfo() === '/boom') {
                throw new \RuntimeException('boom');
            }

            return new JsonResponse(['seen' => $seen, 'path' => $request->getPathInfo()]);
        });

        [$statuses, $bodies] = $this->drive(
            new WorkerRunner($kernel),
            [
                self::request([
                    'uri' => '/boom?x=1',
                    'method' => 'POST',
                    'headers' => [
                        'Host' => 'example.test',
                        'Cookie' => 'a=1',
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'body' => 'posted=1',
                ]),
                self::request(['uri' => '/after']),
            ],
        );

        self::assertSame(500, $statuses[0], 'a throwing kernel is a 500, not a dead worker');
        self::assertSame(200, $statuses[1], 'the worker keeps serving');
        self::assertSame(2, $calls);

        $payload = json_decode($bodies[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/after', $payload['path']);
        self::assertSame([[], [], []], $payload['seen'], 'the failed request left nothing behind');
    }

    /**
     * Drive a runner over an in-memory channel and return every answer's status and body.
     *
     * The real loop is used rather than calling `dispatch()` twice, because the `finally` that
     * resets superglobals is inside `run()` ? a test that skipped it would be asserting the
     * wrong function.
     *
     * @param list<VolucerRequest> $requests
     * @return array{0: list<int>, 1: list<string>}
     */
    private function drive(WorkerRunner $runner, array $requests): array
    {
        $server = fopen('php://memory', 'r+');
        $client = fopen('php://memory', 'r+');

        fwrite($client, (new \Volucer\Frame(\Volucer\MessageType::HELLO, 0))->encode());

        foreach ($requests as $index => $request) {
            $payload = json_encode([
                'method' => $request->method,
                'uri' => $request->uri,
                'protocol' => $request->protocol,
                'headers' => $request->headers,
                'remote_addr' => $request->remoteAddr,
                'body_len' => strlen($request->body),
                'script_filename' => $request->scriptFilename,
                'https' => $request->https,
            ], JSON_THROW_ON_ERROR);

            fwrite($client, (new \Volucer\Frame(\Volucer\MessageType::REQUEST, $index + 1, $payload))->encode());
            if ($request->body !== '') {
                fwrite($client, (new \Volucer\Frame(
                    \Volucer\MessageType::BODY,
                    $index + 1,
                    $request->body,
                    \Volucer\Header::FLAG_LAST,
                ))->encode());
            }
        }

        rewind($client);
        $worker = new \Volucer\Worker($client, $server, fopen('php://memory', 'w+'));
        $runner->run($worker);

        rewind($server);
        $raw = stream_get_contents($server);

        $statuses = [];
        $bodies = [];
        $offset = 0;
        $current = null;
        while ($offset < strlen($raw)) {
            $header = \Volucer\Header::decode(substr($raw, $offset, \Volucer\Header::HEADER_LEN));
            $payload = substr($raw, $offset + \Volucer\Header::HEADER_LEN, $header->payloadLen);
            $offset += \Volucer\Header::HEADER_LEN + $header->payloadLen;

            if ($header->messageType === \Volucer\MessageType::RESPONSE) {
                $current = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                $statuses[] = $current['status'];
                $bodies[] = '';
            } elseif ($header->messageType === \Volucer\MessageType::BODY) {
                $bodies[count($bodies) - 1] .= $payload;
            }
        }

        return [$statuses, $bodies];
    }
}
