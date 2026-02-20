<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolResult;

#[CoversClass(ToolResult::class)]
final class ToolResultTest extends TestCase
{
    #[Test]
    public function successFactoryCreatesNonErrorResult(): void
    {
        $result = ToolResult::success(
            structuredContent: ['routes' => ['/api/v1']],
            textContent: '1 route found',
            meta: ['timing_ms' => 42],
        );

        self::assertSame(['routes' => ['/api/v1']], $result->structuredContent);
        self::assertSame('1 route found', $result->textContent);
        self::assertFalse($result->isError);
        self::assertSame(['timing_ms' => 42], $result->meta);
    }

    #[Test]
    public function successWithoutMeta(): void
    {
        $result = ToolResult::success(
            structuredContent: ['data' => true],
            textContent: 'OK',
        );

        self::assertSame([], $result->meta);
        self::assertFalse($result->isError);
    }

    #[Test]
    public function errorFactoryCreatesErrorResult(): void
    {
        $result = ToolResult::error('Something failed', ['code' => 500]);

        self::assertSame(['error' => 'Something failed'], $result->structuredContent);
        self::assertSame('Something failed', $result->textContent);
        self::assertTrue($result->isError);
        self::assertSame(['code' => 500], $result->meta);
    }

    #[Test]
    public function errorWithoutMeta(): void
    {
        $result = ToolResult::error('Failure');

        self::assertSame(['error' => 'Failure'], $result->structuredContent);
        self::assertSame([], $result->meta);
        self::assertTrue($result->isError);
    }

    #[Test]
    public function truncatedFactoryIncludesTruncationMeta(): void
    {
        $result = ToolResult::truncated(
            structuredContent: ['stdout' => 'partial...'],
            textContent: 'partial...',
            originalBytes: 2_000_000,
            truncatedBytes: 1_048_576,
        );

        self::assertSame(['stdout' => 'partial...'], $result->structuredContent);
        self::assertSame('partial...', $result->textContent);
        self::assertFalse($result->isError);
        self::assertTrue($result->meta['truncated']);
        self::assertSame(2_000_000, $result->meta['original_bytes']);
        self::assertSame(1_048_576, $result->meta['truncated_bytes']);
    }

    #[Test]
    public function directConstructionWithAllFields(): void
    {
        $result = new ToolResult(
            structuredContent: ['key' => 'value'],
            textContent: 'text',
            isError: true,
            meta: ['extra' => 'data'],
        );

        self::assertSame(['key' => 'value'], $result->structuredContent);
        self::assertSame('text', $result->textContent);
        self::assertTrue($result->isError);
        self::assertSame(['extra' => 'data'], $result->meta);
    }
}
