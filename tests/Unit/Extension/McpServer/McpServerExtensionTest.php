<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\McpServerExtension;
use Pulsar\Extension\McpServer\McpServerServiceProvider;

#[CoversClass(McpServerExtension::class)]
#[CoversClass(McpServerServiceProvider::class)]
final class McpServerExtensionTest extends TestCase
{
    #[Test]
    public function extensionName(): void
    {
        $extension = new McpServerExtension();

        self::assertSame('pulsar/mcp-server', $extension->name());
    }

    #[Test]
    public function extensionProviders(): void
    {
        $extension = new McpServerExtension();

        $providers = $extension->providers();

        self::assertContains(McpServerServiceProvider::class, $providers);
    }

    #[Test]
    public function serviceProviderProvidesCorrectBindings(): void
    {
        $provider = new McpServerServiceProvider();

        $provides = $provider->provides();

        self::assertNotEmpty($provides);
        self::assertContains('Pulsar\\Extension\\McpServer\\Contracts\\McpToolRegistryInterface', $provides);
        self::assertContains('Pulsar\\Extension\\McpServer\\Internal\\Protocol\\MessageHandler', $provides);
        self::assertContains('Pulsar\\Extension\\McpServer\\Internal\\Protocol\\StdioTransport', $provides);
    }
}
