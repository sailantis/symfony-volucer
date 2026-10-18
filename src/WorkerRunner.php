<?php

declare(strict_types=1);

namespace Sailantis\SymfonyVolucer;

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Volucer\Request as VolucerRequest;
use Volucer\Response as VolucerResponse;
use Volucer\Worker;
use Throwable;

/**
 * Serve a Symfony application from a volucer worker.
 *
 * The acceptance criterion for a worker bridge is **not** "it serves a request" — it is *two
 * consecutive requests that must not see each other's state*. Symfony is the framework where
 * that is most nearly free, because a kernel is designed to be booted and shut down, and
 * Symfony's own answer to a long-lived process is to do exactly that between requests. So this
 * class does not invent a reset; it uses the one the framework already has:
 *
 * - `kernel->handle()` builds the request's `RequestStack`, dispatches the events, and unwinds
 *   them afterwards, so a listener that wrote to a request-scoped service sees it go away.
 * - `kernel->terminate()` runs `kernel.terminate` listeners, which is where a SAPI would have
 *   sent the response — so this is called *after* the response is read, the same order a
 *   front controller uses.
 * - `kernel->shutdown()` is **not** called per request. It is what a real reboot would need
 *   (`Kernel::reboot()` exists for exactly this), but a reboot per request redoes the boot the
 *   worker model exists to avoid. `services_resetter` — which the framework bundle registers —
 *   is the supported middle ground, and it is what `Kernel::handle()` already uses internally
 *   between requests when the kernel is not rebooted.
 *
 * ## The one thing a kernel does not reset, and this class does
 *
 * `Kernel::handle()` refuses a second request while one is in flight (`__construct`-time state
 * aside, it throws on a nested handle without `MASTER_REQUEST`). That is not the issue here, as
 * the protocol is serial. What *is* the issue is `Request::createFromGlobals()` state: a
 * framework bundle's `kernel.request` listeners read `$_SERVER` and `$_COOKIE` directly in a few
 * places, and PHP leaves both behind. So both are **cleared** after every request, exactly as
 * the Azera adapter does, and the session id is carried in and out explicitly because a worker's
 * SAPI does not write a `Set-Cookie` for free.
 */
final class WorkerRunner
{
    private readonly RequestFactory $requests;

    /**
     * @param list<string>|null $trustedProxies
     */
    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly ?array $trustedProxies = null,
        ?RequestFactory $requests = null,
    ) {
        $this->requests = $requests ?? new RequestFactory();
    }

    /**
     * Serve requests until the server closes the channel.
     */
    public function run(?Worker $worker = null): void
    {
        $worker ??= Worker::connect();

        $worker->run(function (VolucerRequest $request, Worker $worker): VolucerResponse {
            try {
                return $this->dispatch($request);
            } catch (Throwable $error) {
                // One bad request must not cost the worker: a long-lived process that dies
                // takes the requests behind it with it. The exception is reported on stderr,
                // which is not the protocol channel, so an operator still sees it.
                $worker->emitLog(
                    'unhandled ' . $error::class . ': ' . $error->getMessage(),
                    'error',
                );

                return new VolucerResponse(
                    500,
                    [['Content-Type', 'text/plain; charset=utf-8']],
                    "Internal Server Error\n",
                );
            } finally {
                // Reset the per-request state a SAPI would have discarded with the process.
                // `$_SERVER` and `$_COOKIE` are both read directly by framework listeners, and
                // both are left behind in a long-lived process.
                $_SERVER = [];
                $_COOKIE = [];
                $_GET = [];
                $_POST = [];
                $_FILES = [];
                $_REQUEST = [];
            }
        });
    }

    /**
     * Run one request through the kernel.
     *
     * Public and free of I/O so the pipeline can be tested without a pipe — which is what makes
     * the reset behaviour testable at all.
     */
    public function dispatch(VolucerRequest $request): VolucerResponse
    {
        $symfony = $this->requests->create($request, $this->trustedProxies);

        // A kernel's request stack reads this; a framework bundle's session listener reads
        // `$_COOKIE` directly, so both are populated rather than one being assumed.
        $_COOKIE = $symfony->cookies->all();
        $_GET = $symfony->query->all();
        $_POST = $symfony->request->all();
        $_SERVER = $symfony->server->all();

        $response = $this->kernel->handle($symfony, HttpKernelInterface::MAIN_REQUEST);

        // `terminate()` is what a front controller calls after the response is sent, so it is
        // called here after the response is *read* — the order matters because a terminate
        // listener may still write to the response, and the protocol's `body_len` is declared
        // before the body.
        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($symfony, $response);
        }

        return RequestFactory::toVolucerResponse($response);
    }

    /**
     * The kernel this runner serves, for a test that wants to assert the reset itself.
     */
    public function kernel(): HttpKernelInterface
    {
        return $this->kernel;
    }
}
