<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev\Toolbar;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Dev\Toolbar\ToolbarData;

#[CoversClass(ToolbarData::class)]
final class ToolbarDataTest extends TestCase
{
    #[Test]
    public function queryCountReturnsNumberOfQueries(): void
    {
        $data = new ToolbarData(
            requestTimeMs: 10.0,
            memoryPeakBytes: 1024,
            phpVersion: '8.5.0',
            queries: [
                ['sql' => 'SELECT 1', 'time_ms' => 1.0],
                ['sql' => 'SELECT 2', 'time_ms' => 2.0],
                ['sql' => 'SELECT 3', 'time_ms' => 3.0],
            ],
        );

        self::assertSame(3, $data->queryCount());
    }

    #[Test]
    public function queryCountReturnsZeroWhenEmpty(): void
    {
        $data = new ToolbarData(
            requestTimeMs: 10.0,
            memoryPeakBytes: 1024,
            phpVersion: '8.5.0',
        );

        self::assertSame(0, $data->queryCount());
    }

    #[Test]
    public function totalQueryTimeMsSumsAllQueryTimes(): void
    {
        $data = new ToolbarData(
            requestTimeMs: 10.0,
            memoryPeakBytes: 1024,
            phpVersion: '8.5.0',
            queries: [
                ['sql' => 'SELECT 1', 'time_ms' => 1.5],
                ['sql' => 'SELECT 2', 'time_ms' => 2.5],
                ['sql' => 'SELECT 3', 'time_ms' => 3.0],
            ],
        );

        self::assertEqualsWithDelta(7.0, $data->totalQueryTimeMs(), 0.001);
    }

    #[Test]
    public function totalQueryTimeMsReturnsZeroWhenNoQueries(): void
    {
        $data = new ToolbarData(
            requestTimeMs: 10.0,
            memoryPeakBytes: 1024,
            phpVersion: '8.5.0',
        );

        self::assertSame(0.0, $data->totalQueryTimeMs());
    }

    #[Test]
    #[DataProvider('memoryProvider')]
    public function formattedMemoryDisplaysCorrectUnit(int $bytes, string $expectedSuffix): void
    {
        $data = new ToolbarData(
            requestTimeMs: 0.0,
            memoryPeakBytes: $bytes,
            phpVersion: '8.5.0',
        );

        $formatted = $data->formattedMemory();
        self::assertStringContainsString($expectedSuffix, $formatted);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function memoryProvider(): iterable
    {
        yield 'bytes' => [500, 'B'];
        yield 'kilobytes' => [2048, 'KB'];
        yield 'megabytes' => [5 * 1024 * 1024, 'MB'];
        yield 'gigabytes' => [2 * 1024 * 1024 * 1024, 'GB'];
    }

    #[Test]
    public function formattedRequestTimeIncludesMs(): void
    {
        $data = new ToolbarData(
            requestTimeMs: 42.567,
            memoryPeakBytes: 1024,
            phpVersion: '8.5.0',
        );

        self::assertSame('42.6 ms', $data->formattedRequestTime());
    }

    #[Test]
    public function formattedRequestTimeHandlesZero(): void
    {
        $data = new ToolbarData(
            requestTimeMs: 0.0,
            memoryPeakBytes: 1024,
            phpVersion: '8.5.0',
        );

        self::assertSame('0.0 ms', $data->formattedRequestTime());
    }

    #[Test]
    public function allPropertiesAreAccessible(): void
    {
        $data = new ToolbarData(
            requestTimeMs: 15.5,
            memoryPeakBytes: 4096,
            phpVersion: '8.5.4',
            queries: [['sql' => 'SELECT 1', 'time_ms' => 1.0]],
            cacheHits: 10,
            cacheMisses: 3,
            loadedTemplates: ['layout.pulse', 'home.pulse'],
            routeName: 'home',
            controller: 'HomeController::index',
            routePattern: '/home',
        );

        self::assertSame(15.5, $data->requestTimeMs);
        self::assertSame(4096, $data->memoryPeakBytes);
        self::assertSame('8.5.4', $data->phpVersion);
        self::assertCount(1, $data->queries);
        self::assertSame(10, $data->cacheHits);
        self::assertSame(3, $data->cacheMisses);
        self::assertSame(['layout.pulse', 'home.pulse'], $data->loadedTemplates);
        self::assertSame('home', $data->routeName);
        self::assertSame('HomeController::index', $data->controller);
        self::assertSame('/home', $data->routePattern);
    }

    #[Test]
    public function nullablePropertiesDefaultToNull(): void
    {
        $data = new ToolbarData(
            requestTimeMs: 0.0,
            memoryPeakBytes: 0,
            phpVersion: '8.5.0',
        );

        self::assertNull($data->routeName);
        self::assertNull($data->controller);
        self::assertNull($data->routePattern);
    }
}
