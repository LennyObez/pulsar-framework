<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolDefinition;

final class ToolDefinitionTest extends TestCase
{
    #[Test]
    public function constructsWithAllFields(): void
    {
        $def = new ToolDefinition(
            name: 'read_routes',
            description: 'Read application routes',
            inputSchema: ['type' => 'object', 'properties' => []],
            outputSchema: ['type' => 'object'],
            category: ToolCategory::Read,
        );

        self::assertSame('read_routes', $def->name);
        self::assertSame('Read application routes', $def->description);
        self::assertSame(['type' => 'object', 'properties' => []], $def->inputSchema);
        self::assertSame(['type' => 'object'], $def->outputSchema);
        self::assertSame(ToolCategory::Read, $def->category);
    }
}
