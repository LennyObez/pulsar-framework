<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Config\McpToolsConfig;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;
use Pulsar\Extension\McpServer\Internal\Security\ToolPermissionChecker;

#[CoversClass(ToolPermissionChecker::class)]
final class ToolPermissionCheckerTest extends TestCase
{
    #[Test]
    public function readToolIsAllowedByDefault(): void
    {
        $tool = self::createStub(McpToolInterface::class);
        $tool->method('category')->willReturn(ToolCategory::Read);

        $registry = self::createStub(McpToolRegistryInterface::class);
        $registry->method('has')->willReturn(true);
        $registry->method('get')->willReturn($tool);

        $config = McpToolsConfig::fromArray([]);
        $checker = new ToolPermissionChecker($config, $registry);

        $checker->assertAllowed('read_routes');

        self::assertTrue($checker->isAllowed('read_routes'));
    }

    #[Test]
    public function disabledReadToolIsRejected(): void
    {
        $tool = self::createStub(McpToolInterface::class);
        $tool->method('category')->willReturn(ToolCategory::Read);

        $registry = self::createStub(McpToolRegistryInterface::class);
        $registry->method('has')->willReturn(true);
        $registry->method('get')->willReturn($tool);

        $config = McpToolsConfig::fromArray([
            'disabled_read_tools' => ['read_routes'],
        ]);
        $checker = new ToolPermissionChecker($config, $registry);

        $this->expectException(McpSecurityException::class);

        $checker->assertAllowed('read_routes');
    }

    #[Test]
    public function actionToolRequiresExplicitAllowlist(): void
    {
        $tool = self::createStub(McpToolInterface::class);
        $tool->method('category')->willReturn(ToolCategory::Action);

        $registry = self::createStub(McpToolRegistryInterface::class);
        $registry->method('has')->willReturn(true);
        $registry->method('get')->willReturn($tool);

        $config = McpToolsConfig::fromArray([
            'allowed_actions' => [],
        ]);
        $checker = new ToolPermissionChecker($config, $registry);

        $this->expectException(McpSecurityException::class);

        $checker->assertAllowed('run_tests');
    }

    #[Test]
    public function allowedActionToolPasses(): void
    {
        $tool = self::createStub(McpToolInterface::class);
        $tool->method('category')->willReturn(ToolCategory::Action);

        $registry = self::createStub(McpToolRegistryInterface::class);
        $registry->method('has')->willReturn(true);
        $registry->method('get')->willReturn($tool);

        $config = McpToolsConfig::fromArray([
            'allowed_actions' => ['run_tests'],
        ]);
        $checker = new ToolPermissionChecker($config, $registry);

        $checker->assertAllowed('run_tests');

        self::assertTrue($checker->isAllowed('run_tests'));
    }
}
