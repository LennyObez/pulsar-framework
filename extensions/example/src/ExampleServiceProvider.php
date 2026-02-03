<?php

declare(strict_types=1);

namespace Pulsar\Extension\Example;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;

/**
 * Service provider for the example extension.
 */
final class ExampleServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(ExampleService::class, ExampleService::class);
    }

    public function provides(): array
    {
        return [
            ExampleService::class,
        ];
    }
}
