<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Internal\Protocol\MessageHandler;
use Pulsar\Extension\McpServer\Internal\Protocol\StdioTransport;
use Pulsar\Extension\McpServer\McpServerServiceProvider;

#[CoversClass(McpServerServiceProvider::class)]
final class McpServerServiceProviderTest extends TestCase
{
    #[Test]
    public function providesAdvertisesExpectedBindings(): void
    {
        $provider = new McpServerServiceProvider();

        $provides = $provider->provides();

        self::assertContains(McpToolRegistryInterface::class, $provides);
        self::assertContains(MessageHandler::class, $provides);
        self::assertContains(StdioTransport::class, $provides);
    }
}
