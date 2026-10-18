<?php

declare(strict_types=1);

namespace Sailantis\SymfonyVolucer\Tests;

use PHPUnit\Framework\TestCase;
use Sailantis\SymfonyVolucer\WorkerRunner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * End-to-end: the real server, a real Symfony kernel, the real protocol.
 *
 * Everything else in this suite tests the bridge against its own model. This one is what proves
 * the model is right, because the other end is the Rust server rather than a fixture.
 *
 * It is skipped unless a `volucer` binary is found — `VOLUCER_BIN`, or the sibling checkout.
 * `VOLUCER_E2E=1` turns a skip into a failure, which is the same convention the server's own
 * suites and the two non-PHP SDKs use.
 *
 * The assertions are the acceptance criterion the plan names, not "it serves a request":
 * **two consecutive requests must not see each other's state.**
 */
final class EndToEndTest extends TestCase
{
    private ?string $root = null;

    /** @var resource|null the `proc_open` handle, which is a resource rather than a pid */
    private $process = null;

    private int $port = 0;

    protected function setUp(): void
    {
        $binary = self::binary();
        if ($binary === null) {
            self::markTestSkipped('no volucer binary: set VOLUCER_BIN or build the server checkout');
        }

        $this->root = sys_get_temp_dir() . '/symfony-volucer-e2e-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root . '/app.php', "<?php // the entry point the router resolves\n");

        $this->port = self::freePort();
        $worker = __DIR__ . '/worker.php';

        // A TOML *literal* string, because a Windows path in a basic string turns `\U` into a
        // unicode escape. A literal string cannot carry a single quote, hence the guard.
        // A TOML *literal* string, so a Windows path's backslashes are not unicode escapes.
        // The tokenizer the server uses is not a shell, so no quoting is needed or wanted: a
        // quoted path would arrive with the quotes in it.
        $command = PHP_BINARY . ' ' . $worker;
        if (str_contains($command, "'")) {
            self::fail('the interpreter path contains a single quote');
        }

        $config = $this->root . '/volucer.toml';
        file_put_contents($config, implode("\n", [
            'default_backend = "symfony"',
            '[server]',
            'http = "127.0.0.1:' . $this->port . '"',
            'document_root = "' . str_replace('\\', '/', $this->root) . '"',
            'front_controller = "app.php"',
            "",
            '[backends.symfony]',
            'kind = "worker"',
            "command = '" . $command . "'",
            'workers = 1',
            'max_requests = 0',
        ]) . "\n");

        $this->process = self::spawn($binary, $config, $this->root);
        $this->waitUntilReady();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            // `TERM` is not a signal Windows has, so the cross-platform spelling is a hard kill
            // there; on Unix the graceful one is what lets the server reap its own workers.
            @proc_terminate($this->process, DIRECTORY_SEPARATOR === '\\' ? 9 : 15);
            @proc_close($this->process);
            $this->process = null;
        }

        if ($this->root !== null && is_dir($this->root)) {
            foreach (glob($this->root . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->root);
            $this->root = null;
        }
    }

    public function test_two_requests_do_not_see_each_others_state(): void
    {
        $first = $this->get('/items?marker=one', ['Cookie: a=1']);
        self::assertSame(200, $first['status'], $first['body']);
        $payload = json_decode($first['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/items', $payload['path']);
        self::assertSame(['marker' => 'one'], $payload['query']);
        self::assertSame(['a' => '1'], $payload['cookies']);

        $second = $this->get('/after');
        self::assertSame(200, $second['status'], $second['body']);
        $payload = json_decode($second['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/after', $payload['path']);
        self::assertSame([], $payload['query'], 'the second request must not inherit the first query');
        self::assertSame([], $payload['cookies'], 'nor its cookies');
    }

    public function test_a_form_post_reaches_request(): void
    {
        $answer = $this->request('POST', '/submit', [
            'Host: example.test',
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: 11',
        ], 'name=widget');

        self::assertSame(200, $answer['status'], $answer['body']);
        $payload = json_decode($answer['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['name' => 'widget'], $payload['post']);
    }

    // ---------------------------------------------------------------- helpers

    private static function binary(): ?string
    {
        $explicit = getenv('VOLUCER_BIN');
        if (is_string($explicit) && $explicit !== '' && is_file($explicit)) {
            return $explicit;
        }

        $sibling = dirname(__DIR__, 2) . '/volucer/target/debug/volucer' . (DIRECTORY_SEPARATOR === '\\' ? '.exe' : '');

        return is_file($sibling) ? $sibling : null;
    }

    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($probe === false) {
            self::fail("cannot bind a probe socket: {$error}");
        }
        $name = stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    /**
     * @return resource
     */
    private static function spawn(string $binary, string $config, string $root)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $root . '/stdout.log', 'w'],
            2 => ['file', $root . '/stderr.log', 'w'],
        ];
        $process = proc_open(
            [$binary, 'serve', '--config', $config],
            $descriptors,
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('the server did not start');
        }

        return $process;
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if (!($status['running'] ?? false)) {
                self::fail('the server exited before answering');
            }

            $probe = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.5);
            if (is_resource($probe)) {
                fclose($probe);
                return;
            }

            usleep(50_000);
        }

        self::fail('the server never answered');
    }

    /**
     * @param list<string> $extraHeaders
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function get(string $path, array $extraHeaders = []): array
    {
        return $this->request('GET', $path, $extraHeaders);
    }

    /**
     * @param list<string> $extraHeaders
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $path, array $extraHeaders = [], string $body = ''): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 30);
        if ($socket === false) {
            self::fail("cannot connect: {$error}");
        }

        // The caller's headers carry `Host` when it cares; a default is added only when it
        // does not, because two `Host` lines are one comma-joined header by the time the
        // server has translated them ? and Symfony refuses that as a suspicious host.
        $hasHost = false;
        foreach ($extraHeaders as $header) {
            if (stripos($header, 'Host:') === 0) {
                $hasHost = true;
            }
        }

        $request = "{$method} {$path} HTTP/1.1\r\n";
        if (!$hasHost) {
            $request .= "Host: example.test\r\n";
        }
        foreach ($extraHeaders as $header) {
            $request .= $header . "\r\n";
        }
        if ($method !== 'GET' && !in_array('Content-Length: ' . strlen($body), $extraHeaders, true)) {
            $request .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        $request .= "Connection: close\r\n\r\n" . $body;

        fwrite($socket, $request);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $bodyOut] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $head);
        $status = (int) (explode(' ', $lines[0])[1] ?? 0);

        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $bodyOut];
    }
}
