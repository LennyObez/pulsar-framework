<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Internal\Protocol\MessageHandler;
use Pulsar\Extension\McpServer\Internal\Protocol\StdioTransport;
use Pulsar\Extension\McpServer\McpServerServiceProvider;

#[CoversClass(McpServerServiceProvider::class)]
final class McpServerServiceProviderTest extends TestCase
{
    #[Test]
    public function registerIsNoOp(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $provider = new McpServerServiceProvider();

        // register() should complete without throwing
        $provider->register($container);

        self::assertInstanceOf(McpServerServiceProvider::class, $provider);
    }

    #[Test]
    public function providesReturnsExpectedServiceIds(): void
    {
        $provider = new McpServerServiceProvider();
        $provides = $provider->provides();

        self::assertContains(McpToolRegistryInterface::class, $provides);
        self::assertContains(MessageHandler::class, $provides);
        self::assertContains(StdioTransport::class, $provides);
        self::assertCount(3, $provides);
    }
}
