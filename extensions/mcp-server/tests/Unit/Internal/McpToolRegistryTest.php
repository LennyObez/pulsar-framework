<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\McpToolRegistry;

final class McpToolRegistryTest extends TestCase
{
    private function createToolStub(string $name, ToolCategory $category = ToolCategory::Read): McpToolInterface
    {
        $tool = $this->createStub(McpToolInterface::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn("Description for $name");
        $tool->method('inputSchema')->willReturn(['type' => 'object']);
        $tool->method('outputSchema')->willReturn(['type' => 'object']);
        $tool->method('category')->willReturn($category);
        $tool->method('execute')->willReturn(ToolResult::success([], 'OK'));

        return $tool;
    }

    #[Test]
    public function registerAndGetTool(): void
    {
        $registry = new McpToolRegistry();
        $tool = $this->createToolStub('read_routes');

        $registry->register($tool);

        self::assertSame($tool, $registry->get('read_routes'));
    }

    #[Test]
    public function hasReturnsTrueForRegisteredTool(): void
    {
        $registry = new McpToolRegistry();
        $registry->register($this->createToolStub('read_config'));

        self::assertTrue($registry->has('read_config'));
        self::assertFalse($registry->has('nonexistent'));
    }

    #[Test]
    public function getThrowsForUnregisteredTool(): void
    {
        $registry = new McpToolRegistry();

        $this->expectException(McpException::class);
        $registry->get('nonexistent');
    }

    #[Test]
    public function listReturnsAllToolDefinitions(): void
    {
        $registry = new McpToolRegistry();
        $registry->register($this->createToolStub('tool_a'));
        $registry->register($this->createToolStub('tool_b', ToolCategory::Action));

        $defs = $registry->list();

        self::assertCount(2, $defs);
        self::assertSame('tool_a', $defs[0]->name);
        self::assertSame(ToolCategory::Read, $defs[0]->category);
        self::assertSame('tool_b', $defs[1]->name);
        self::assertSame(ToolCategory::Action, $defs[1]->category);
    }

    #[Test]
    public function callExecutesToolByName(): void
    {
        $registry = new McpToolRegistry();
        $tool = $this->createToolStub('my_tool');
        $registry->register($tool);

        $result = $registry->call('my_tool', []);

        self::assertFalse($result->isError);
    }

    #[Test]
    public function callThrowsForUnregisteredTool(): void
    {
        $registry = new McpToolRegistry();

        $this->expectException(McpException::class);
        $registry->call('missing', []);
    }
}
