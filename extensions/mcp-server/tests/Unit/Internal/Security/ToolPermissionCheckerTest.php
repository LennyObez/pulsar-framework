<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Config\McpToolsConfig;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;
use Pulsar\Extension\McpServer\Internal\McpToolRegistry;
use Pulsar\Extension\McpServer\Internal\Security\ToolPermissionChecker;

final class ToolPermissionCheckerTest extends TestCase
{
    private function createToolStub(string $name, ToolCategory $category): McpToolInterface
    {
        $tool = $this->createStub(McpToolInterface::class);
        $tool->method('name')->willReturn($name);
        $tool->method('category')->willReturn($category);
        $tool->method('execute')->willReturn(ToolResult::success([], 'OK'));

        return $tool;
    }

    #[Test]
    public function readToolIsAllowedByDefault(): void
    {
        $registry = new McpToolRegistry();
        $registry->register($this->createToolStub('read_routes', ToolCategory::Read));

        $config = McpToolsConfig::fromArray([]);
        $checker = new ToolPermissionChecker($config, $registry);

        $checker->assertAllowed('read_routes');
        self::assertTrue($checker->isAllowed('read_routes'));
    }

    #[Test]
    public function disabledReadToolIsBlocked(): void
    {
        $registry = new McpToolRegistry();
        $registry->register($this->createToolStub('read_routes', ToolCategory::Read));

        $config = McpToolsConfig::fromArray(['disabled_read_tools' => ['read_routes']]);
        $checker = new ToolPermissionChecker($config, $registry);

        $this->expectException(McpSecurityException::class);
        $checker->assertAllowed('read_routes');
    }

    #[Test]
    public function actionToolNotInAllowlistIsBlocked(): void
    {
        $registry = new McpToolRegistry();
        $registry->register($this->createToolStub('run_tests', ToolCategory::Action));

        $config = McpToolsConfig::fromArray(['allowed_actions' => []]);
        $checker = new ToolPermissionChecker($config, $registry);

        self::assertFalse($checker->isAllowed('run_tests'));
    }

    #[Test]
    public function actionToolInAllowlistIsAllowed(): void
    {
        $registry = new McpToolRegistry();
        $registry->register($this->createToolStub('run_tests', ToolCategory::Action));

        $config = McpToolsConfig::fromArray(['allowed_actions' => ['run_tests']]);
        $checker = new ToolPermissionChecker($config, $registry);

        $checker->assertAllowed('run_tests');
        self::assertTrue($checker->isAllowed('run_tests'));
    }

    #[Test]
    public function unregisteredToolIsBlocked(): void
    {
        $registry = new McpToolRegistry();
        $config = McpToolsConfig::fromArray([]);
        $checker = new ToolPermissionChecker($config, $registry);

        $this->expectException(McpSecurityException::class);
        $checker->assertAllowed('nonexistent');
    }
}
