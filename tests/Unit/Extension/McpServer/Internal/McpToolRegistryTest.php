<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\McpToolRegistry;

#[CoversClass(McpToolRegistry::class)]
final class McpToolRegistryTest extends TestCase
{
    private McpToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new McpToolRegistry();
    }

    private function createToolStub(string $name, ToolCategory $category = ToolCategory::Read): McpToolInterface
    {
        $tool = $this->createStub(McpToolInterface::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn("Description for $name");
        $tool->method('inputSchema')->willReturn(['type' => 'object']);
        $tool->method('outputSchema')->willReturn(['type' => 'object']);
        $tool->method('category')->willReturn($category);

        return $tool;
    }

    #[Test]
    public function registerAndRetrieveTool(): void
    {
        $tool = $this->createToolStub('pulsar.routes.list');

        $this->registry->register($tool);

        self::assertTrue($this->registry->has('pulsar.routes.list'));
        self::assertSame($tool, $this->registry->get('pulsar.routes.list'));
    }

    #[Test]
    public function hasReturnsFalseForUnknownTool(): void
    {
        self::assertFalse($this->registry->has('nonexistent'));
    }

    #[Test]
    public function getThrowsForUnknownTool(): void
    {
        $this->expectException(McpException::class);

        $this->registry->get('nonexistent');
    }

    #[Test]
    public function listReturnsAllToolDefinitions(): void
    {
        $this->registry->register($this->createToolStub('tool.a'));
        $this->registry->register($this->createToolStub('tool.b', ToolCategory::Action));

        $definitions = $this->registry->list();

        self::assertCount(2, $definitions);
        self::assertSame('tool.a', $definitions[0]->name);
        self::assertSame(ToolCategory::Read, $definitions[0]->category);
        self::assertSame('tool.b', $definitions[1]->name);
        self::assertSame(ToolCategory::Action, $definitions[1]->category);
    }

    #[Test]
    public function listReturnsEmptyWhenNoToolsRegistered(): void
    {
        self::assertSame([], $this->registry->list());
    }

    #[Test]
    public function callDelegatesToToolExecute(): void
    {
        $expectedResult = ToolResult::success(['data' => true], 'ok');

        $tool = $this->createStub(McpToolInterface::class);
        $tool->method('name')->willReturn('test.tool');
        $tool->method('execute')->willReturn($expectedResult);

        $this->registry->register($tool);

        $result = $this->registry->call('test.tool', ['param' => 'value']);

        self::assertSame($expectedResult, $result);
    }

    #[Test]
    public function callThrowsForUnknownTool(): void
    {
        $this->expectException(McpException::class);

        $this->registry->call('nonexistent', []);
    }

    #[Test]
    public function registerOverwritesExistingToolWithSameName(): void
    {
        $tool1 = $this->createToolStub('same.name');
        $tool2 = $this->createToolStub('same.name');

        $this->registry->register($tool1);
        $this->registry->register($tool2);

        self::assertSame($tool2, $this->registry->get('same.name'));
        self::assertCount(1, $this->registry->list());
    }
}
