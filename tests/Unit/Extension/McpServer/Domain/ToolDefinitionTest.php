<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolDefinition;

#[CoversClass(ToolDefinition::class)]
final class ToolDefinitionTest extends TestCase
{
    #[Test]
    public function readToolDefinition(): void
    {
        $definition = new ToolDefinition(
            name: 'pulsar.routes.list',
            description: 'List registered routes',
            inputSchema: ['type' => 'object', 'properties' => ['method' => ['type' => 'string']]],
            outputSchema: ['type' => 'object', 'properties' => ['routes' => ['type' => 'array']]],
            category: ToolCategory::Read,
        );

        self::assertSame('pulsar.routes.list', $definition->name);
        self::assertSame('List registered routes', $definition->description);
        self::assertSame(ToolCategory::Read, $definition->category);
        self::assertArrayHasKey('properties', $definition->inputSchema);
        self::assertArrayHasKey('properties', $definition->outputSchema);
    }

    #[Test]
    public function actionToolDefinition(): void
    {
        $definition = new ToolDefinition(
            name: 'pulsar.tests.run',
            description: 'Run PHPUnit tests',
            inputSchema: ['type' => 'object'],
            outputSchema: ['type' => 'object'],
            category: ToolCategory::Action,
        );

        self::assertSame(ToolCategory::Action, $definition->category);
        self::assertSame('pulsar.tests.run', $definition->name);
    }
}
