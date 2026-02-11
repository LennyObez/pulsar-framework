<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolDefinition;
use Pulsar\Extension\McpServer\Domain\ToolResult;

#[CoversClass(ToolCategory::class)]
#[CoversClass(ToolDefinition::class)]
#[CoversClass(ToolResult::class)]
final class McpDomainTest extends TestCase
{
    // --- ToolCategory ---

    #[Test]
    public function toolCategoryValues(): void
    {
        self::assertSame('read', ToolCategory::Read->value);
        self::assertSame('action', ToolCategory::Action->value);
    }

    // --- ToolDefinition ---

    #[Test]
    public function toolDefinitionConstruction(): void
    {
        $definition = new ToolDefinition(
            name: 'pulsar.api.snapshot',
            description: 'Returns the public API snapshot',
            inputSchema: ['type' => 'object', 'properties' => ['class' => ['type' => 'string']]],
            outputSchema: ['type' => 'object'],
            category: ToolCategory::Read,
        );

        self::assertSame('pulsar.api.snapshot', $definition->name);
        self::assertSame('Returns the public API snapshot', $definition->description);
        self::assertArrayHasKey('type', $definition->inputSchema);
        self::assertSame(ToolCategory::Read, $definition->category);
    }

    // --- ToolResult ---

    #[Test]
    public function toolResultConstruction(): void
    {
        $result = new ToolResult(
            structuredContent: ['routes' => ['/api/v1/users']],
            textContent: '1 route found',
            isError: false,
            meta: ['duration_ms' => 12],
        );

        self::assertSame(['routes' => ['/api/v1/users']], $result->structuredContent);
        self::assertSame('1 route found', $result->textContent);
        self::assertFalse($result->isError);
        self::assertSame(['duration_ms' => 12], $result->meta);
    }

    #[Test]
    public function toolResultSuccessFactory(): void
    {
        $result = ToolResult::success(
            ['bindings' => 42],
            '42 container bindings',
            ['cached' => true],
        );

        self::assertSame(['bindings' => 42], $result->structuredContent);
        self::assertSame('42 container bindings', $result->textContent);
        self::assertFalse($result->isError);
        self::assertSame(['cached' => true], $result->meta);
    }

    #[Test]
    public function toolResultErrorFactory(): void
    {
        $result = ToolResult::error('Analysis failed: parse error on line 42');

        self::assertSame(['error' => 'Analysis failed: parse error on line 42'], $result->structuredContent);
        self::assertSame('Analysis failed: parse error on line 42', $result->textContent);
        self::assertTrue($result->isError);
    }

    #[Test]
    public function toolResultSuccessWithDefaultMeta(): void
    {
        $result = ToolResult::success(['data' => true], 'OK');

        self::assertSame([], $result->meta);
    }
}
