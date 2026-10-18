<?php

declare(strict_types=1);

namespace Sailantis\SymfonyVolucer\DependencyInjection;

use Sailantis\SymfonyVolucer\WorkerRunner;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Register the worker runner from the kernel.
 *
 * The kernel itself is injected by reference rather than fetched from the container as
 * `kernel`, because a bundle that looked its own host up by id would work in a full-stack
 * application and fail in a test container — and the thing this class registers is *the*
 * kernel's own runner.
 */
final class SymfonyVolucerExtension extends Extension
{
    /**
     * @param array<array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        if ($config['enabled'] !== true) {
            return;
        }

        $runner = new Definition(WorkerRunner::class);
        $runner->setPublic(true);
        $runner->setArguments([
            new Reference('kernel'),
            Configuration::trustedProxies($config),
        ]);

        $container->setDefinition(Configuration::RUNNER, $runner);
    }

    public function getAlias(): string
    {
        return 'symfony_volucer';
    }
}
