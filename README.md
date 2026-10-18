# symfony-volucer

Run a Symfony application on [volucer](https://github.com/sailantis/volucer)'s persistent worker
protocol.

A **bundle**, not an upstream change — the shape `baldinof/roadrunner-bundle` established, and the
only one available: Symfony's kernel has no "server" concept to extend, so a bridge is a package
that boots the kernel and calls `handle()` in a loop.

## Why

Worker mode boots the application once and serves many requests from that process, so a framework's
bootstrap cost stops being charged per request. On a real application that is a multiple rather than
a rounding error — measured, in the server's own plan, at 7.5× for a Laravel `GET /` and by a
comparable factor for Symfony.

The alternative is the server's `upstream` backend, which supervises Puma/gunicorn/FrankenPHP over
HTTP and needs no package. This bundle exists for the case where the worker channel itself is what
you want: the `kv`, `lock`, `jobs` and `metrics` services, recycling on RSS, and no HTTP framing on
the hot path.

## Install

```console
composer require sailantis/symfony-volucer
```

Register the bundle in `config/bundles.php`:

```php
return [
    // …
    Sailantis\SymfonyVolucer\SymfonyVolucerBundle::class => ['all' => true],
];
```

## Use

Create a worker entry point. This is the whole of it:

```php
<?php
// bin/volucer-worker.php

use App\Kernel;
use Sailantis\SymfonyVolucer\SymfonyVolucerBundle;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'prod', false);
$kernel->boot();

$kernel->getContainer()
    ->get(SymfonyVolucerBundle::class)
    ->serve($kernel);
```

Point a backend at it:

```toml
[backends.symfony]
kind    = "worker"
command = "php bin/volucer-worker.php"
workers = 8
# memory_limit_mb = 256
```

`serve()` calls `Worker::connect()`, so `VOLUCER_WORKER_SOCKET` is honoured and stdio is the
fallback. That matters: a worker entry point that called `Worker::create()` would hang on the socket
transport, which is the one mistake this API is shaped to prevent.

## What the bridge resets, and why that is the point

The acceptance criterion is **not** "it serves a request" — it is *two consecutive requests that
must not see each other's state*. Symfony is where that is most nearly free, because a kernel is
designed to be booted and shut down. So the bridge uses what the framework already has instead of
inventing a reset:

| What | How |
| --- | --- |
| Request-scoped services | `kernel->handle()` builds and unwinds the `RequestStack`, so a listener that wrote to one sees it go away |
| `kernel.terminate` listeners | `kernel->terminate()` is called **after** the response is read, which is the order a front controller uses — and the protocol declares `body_len` before the body, so a listener must not be able to change it afterwards |
| `$_SERVER`, `$_COOKIE`, `$_GET`, `$_POST`, `$_FILES`, `$_REQUEST` | cleared after every request, because a few framework listeners read them directly and PHP leaves them behind in a long-lived process |
| Sessions | not handled here. A worker's SAPI does not write `Set-Cookie` for free, and a Symfony application that uses sessions needs the session id read in and the cookie written out — see the "Sessions" section of the server's `docs/worker-protocol.md` |

What the bridge deliberately does **not** do is call `Kernel::reboot()` per request. That is what a
real reboot would need, and it redoes the boot the worker model exists to avoid.

## The two things a worker cannot copy

Both are in `RequestFactory`, and both are silent failures when wrong:

**`$request->request` is empty unless the body is parsed.** Under a SAPI PHP has already filled
`$_POST`; a worker's body is bytes on the wire. So the bridge parses
`application/x-www-form-urlencoded` the way PHP does, and **deliberately not**
`multipart/form-data` — the server has consumed the request, so the boundary is the only thing that
could be parsed from, and a half-parsed upload is worse than an empty one. A deployment that needs
uploads uses `upstream`.

**`$request->cookies` is empty unless the `Cookie` header is parsed.** `Request::create()` does not
do it, because it expects a SAPI to have done so. Without it every request looks like a client's
first, which is exactly the kind of bug that only shows up under a session.

Two smaller ones: `REMOTE_ADDR` is stated, or Symfony's trusted-proxy machinery sees `null` and
drops every `X-Forwarded-For`; and `SERVER_PORT` comes from the `Host` header, because a redirect
generated from a wrong port is a link to nothing.

## Configuration

```yaml
# config/packages/symfony_volucer.yaml
symfony_volucer:
    enabled: true
    trusted_proxies: ['10.0.0.0/8']
```

`enabled: false` keeps the same kernel bootable in a normal web process without exposing a worker
entry point — useful when one image serves both. `trusted_proxies` is **empty by default** and the
header is ignored when it is: a worker has no socket, so it cannot tell a proxy from a client, and
believing a header by default is how a spoofed address gets in.

## Tests

```console
composer test
```

16 tests. Most are unit tests against a closure-backed kernel; two drive a **real `volucer` binary
with a real worker process**, because the things that can go wrong here — a socket transport that
was never connected, a superglobal that survived — are exactly the things a self-consistent fixture
cannot see.

The end-to-end tests skip without a binary and are found at `VOLUCER_BIN` or in the sibling
checkout at `../volucer/target/debug/volucer`. Set `VOLUCER_E2E=1` to make a skip a failure, which
is what CI does — a skipped end-to-end test and a passing one are the same green.

## What this bundle does not do

- **Multiplexed or async requests.** The channel carries one request at a time, so concurrency is
  `workers` — one process per worker. That is a protocol property, not an adapter gap.
- **Streamed responses.** `RESPONSE` declares `body_len` before the body. A `StreamedResponse` is
  read into memory and sent whole; a genuinely streaming endpoint needs the `upstream` backend.
- **`Kernel::reboot()` per request.** See above.
