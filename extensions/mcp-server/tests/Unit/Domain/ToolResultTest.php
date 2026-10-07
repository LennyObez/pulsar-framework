<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolResult;

final class ToolResultTest extends TestCase
{
    #[Test]
    public function successFactoryCreatesNonErrorResult(): void
    {
        $result = ToolResult::success(['routes' => []], 'Found 0 routes');

        self::assertSame(['routes' => []], $result->structuredContent);
        self::assertSame('Found 0 routes', $result->textContent);
        self::assertFalse($result->isError);
        self::assertSame([], $result->meta);
    }

    #[Test]
    public function successFactoryAcceptsMeta(): void
    {
        $result = ToolResult::success([], 'OK', ['timing_ms' => 42]);

        self::assertSame(['timing_ms' => 42], $result->meta);
    }

    #[Test]
    public function errorFactoryCreatesErrorResult(): void
    {
        $result = ToolResult::error('Something failed');

        self::assertSame(['error' => 'Something failed'], $result->structuredContent);
        self::assertSame('Something failed', $result->textContent);
        self::assertTrue($result->isError);
    }

    #[Test]
    public function errorFactoryAcceptsMeta(): void
    {
        $result = ToolResult::error('Fail', ['code' => 500]);

        self::assertSame(['code' => 500], $result->meta);
    }

    #[Test]
    public function truncatedFactoryIncludesTruncationMeta(): void
    {
        $result = ToolResult::truncated(['data' => '...'], 'Truncated output', 50000, 10000);

        self::assertFalse($result->isError);
        self::assertTrue($result->meta['truncated']);
        self::assertSame(50000, $result->meta['original_bytes']);
        self::assertSame(10000, $result->meta['truncated_bytes']);
    }

    #[Test]
    public function constructorAllowsDirectInstantiation(): void
    {
        $result = new ToolResult(
            structuredContent: ['key' => 'val'],
            textContent: 'text',
            isError: false,
            meta: ['custom' => true],
        );

        self::assertSame(['key' => 'val'], $result->structuredContent);
        self::assertSame('text', $result->textContent);
        self::assertSame(['custom' => true], $result->meta);
    }
}
