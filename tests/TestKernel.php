<?php

declare(strict_types=1);

namespace Sailantis\SymfonyVolucer\Tests;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * A kernel whose handlers are closures, for testing the bridge without an application.
 *
 * Deliberately implements {@see TerminableInterface}, because the ordering of `terminate()`
 * relative to reading the response is one of the things that can go wrong and a kernel that did
 * not implement it would not exercise the branch.
 */
final class TestKernel implements HttpKernelInterface, TerminableInterface
{
    /** @var list<string> */
    public array $terminated = [];

    /**
     * @param callable(Request): Response $handler
     */
    public function __construct(private $handler)
    {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return ($this->handler)($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->terminated[] = $request->getPathInfo();
    }
}
