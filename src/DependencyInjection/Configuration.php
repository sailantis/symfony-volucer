<?php

declare(strict_types=1);

namespace Sailantis\SymfonyVolucer\DependencyInjection;

use Sailantis\SymfonyVolucer\WorkerRunner;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The bundle's configuration.
 *
 * Small on purpose: a worker bridge has almost nothing to configure, because the settings that
 * matter — how many workers, how much memory, where the document root is — belong to the
 * *server*, not to the application. What is left is the one thing the server cannot know:
 * which peers to believe when they claim a forwarded address.
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * The service id the extension registers, so the extension and the tests agree on one name.
     */
    public const RUNNER = WorkerRunner::class;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('symfony_volucer');
        $root = $tree->getRootNode();

        $root
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Register the worker runner. Turn this off to boot the same kernel in a normal web process without exposing a worker entry point.')
                ->end()
                ->arrayNode('trusted_proxies')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('CIDRs whose X-Forwarded-For is believed. Empty means the header is ignored, which is the safe default: a worker has no socket and cannot tell a proxy from a client.')
                ->end()
            ->end()
        ;

        return $tree;
    }

    /**
     * The trust list to hand the request factory, from this configuration.
     *
     * `null` means "do not configure", which is the right answer for an empty list: a worker
     * behind a server that already resolved the forwarded address does not need to resolve it
     * again, and believing a header by default is how a spoofed address gets in.
     *
     * @param array<string, mixed> $config
     * @return list<string>|null
     */
    public static function trustedProxies(array $config): ?array
    {
        $proxies = $config['trusted_proxies'] ?? [];

        return $proxies === [] ? null : array_values(array_map('strval', $proxies));
    }
}
