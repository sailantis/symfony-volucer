<?php

declare(strict_types=1);

namespace Sailantis\SymfonyVolucer;

use Sailantis\SymfonyVolucer\DependencyInjection\Configuration;
use Sailantis\SymfonyVolucer\DependencyInjection\SymfonyVolucerExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The bundle.
 *
 * A bundle rather than an upstream change, which is the shape `baldinof/roadrunner-bundle`
 * established for Symfony and the only shape available: Symfony's kernel has no "server" concept
 * to extend, so a bridge is a package that boots the kernel and calls `handle()` in a loop.
 */
final class SymfonyVolucerBundle extends Bundle
{
    public function getContainerExtension(): ?SymfonyVolucerExtension
    {
        return new SymfonyVolucerExtension();
    }

    /**
     * Serve the kernel this bundle was registered on.
     *
     * The intended front controller is two lines:
     *
     * ```php
     * $kernel = new Kernel($_SERVER['APP_ENV'] ?? 'prod', true);
     * $kernel->boot();
     * $kernel->getContainer()->get(SymfonyVolucerBundle::class)->serve($kernel);
     * ```
     *
     * `Worker::connect()` is what this calls, so `VOLUCER_WORKER_SOCKET` is honoured and stdio is
     * the fallback. A worker that called `create()` instead would hang on the socket transport,
     * which is why the entry point is here rather than in a README snippet an application writes.
     */
    public function serve(
        \Symfony\Component\HttpKernel\HttpKernelInterface $kernel,
        ?\Volucer\Worker $worker = null,
    ): void {
        $runner = $kernel->getContainer()->get(Configuration::RUNNER);

        if (!$runner instanceof WorkerRunner) {
            throw new \RuntimeException(
                'The symfony_volucer extension is disabled, so there is no worker runner to '
                . 'serve; set `symfony_volucer.enabled: true` or stop naming this bundle in a '
                . 'worker entry point.',
            );
        }

        $runner->run($worker);
    }
}
