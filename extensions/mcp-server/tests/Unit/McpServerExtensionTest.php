<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\McpServerExtension;
use Pulsar\Extension\McpServer\McpServerServiceProvider;

final class McpServerExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarMcpServer(): void
    {
        self::assertSame('pulsar/mcp-server', new McpServerExtension()->name());
    }

    #[Test]
    public function providersReturnsMcpServerServiceProvider(): void
    {
        $providers = new McpServerExtension()->providers();

        self::assertCount(1, $providers);
        self::assertSame(McpServerServiceProvider::class, $providers[0]);
    }
}
