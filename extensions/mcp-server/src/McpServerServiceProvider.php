<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Internal\Protocol\MessageHandler;
use Pulsar\Extension\McpServer\Internal\Protocol\StdioTransport;

/**
 * Service provider advertising MCP server bindings.
 */
final readonly class McpServerServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Bindings are created in McpServerExtension::preBoot()
        // This provider exists to advertise the service IDs.
    }

    public function provides(): array
    {
        return [
            McpToolRegistryInterface::class,
            MessageHandler::class,
            StdioTransport::class,
        ];
    }
}
