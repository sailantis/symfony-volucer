<?php

declare(strict_types=1);

/**
 * The worker entry point the end-to-end test runs.
 *
 * It is what a real front controller would be: boot the kernel, then hand it to the worker. The
 * only difference is the kernel, which is {@see \Sailantis\SymfonyVolucer\Tests\TestKernel} — a
 * closure-backed kernel — so this proves the *bridge* rather than an application.
 */

require __DIR__ . '/../vendor/autoload.php';

use Sailantis\SymfonyVolucer\Tests\TestKernel;
use Sailantis\SymfonyVolucer\WorkerRunner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$kernel = new TestKernel(static function (Request $request): Response {
    return new JsonResponse([
        'method' => $request->getMethod(),
        'path' => $request->getPathInfo(),
        'query' => $request->query->all(),
        'post' => $request->request->all(),
        'cookies' => $request->cookies->all(),
        'scheme' => $request->getScheme(),
        'host' => $request->getHost(),
    ]);
});

(new WorkerRunner($kernel))->run();
