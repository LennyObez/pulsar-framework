<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Internal\Export\JsonExportDriver;

#[CoversClass(JsonExportDriver::class)]
final class JsonExportDriverTest extends TestCase
{
    private JsonExportDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new JsonExportDriver();
    }

    #[Test]
    public function formatReturnsJson(): void
    {
        self::assertSame(ExportFormat::Json, $this->driver->format());
    }

    #[Test]
    public function mimeTypeIsCorrect(): void
    {
        self::assertSame('application/json; charset=utf-8', $this->driver->mimeType());
    }

    #[Test]
    public function fileExtensionIsJson(): void
    {
        self::assertSame('json', $this->driver->fileExtension());
    }

    #[Test]
    public function exportProducesValidJson(): void
    {
        $output = $this->driver->export(
            ['id', 'name'],
            [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('data', $decoded);
        self::assertArrayHasKey('columns', $decoded);
        self::assertArrayHasKey('total', $decoded);
    }

    #[Test]
    public function exportIncludesDataRows(): void
    {
        $output = $this->driver->export(
            ['id', 'name'],
            [
                ['id' => 1, 'name' => 'Alice'],
            ],
        );

        /** @var array{data: list<array<string, mixed>>, columns: list<string>, total: int} $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $decoded['data']);
        self::assertSame(1, $decoded['data'][0]['id']);
        self::assertSame('Alice', $decoded['data'][0]['name']);
    }

    #[Test]
    public function exportIncludesColumnNames(): void
    {
        $output = $this->driver->export(['id', 'name', 'email'], []);

        /** @var array{columns: list<string>} $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['id', 'name', 'email'], $decoded['columns']);
    }

    #[Test]
    public function exportIncludesTotalCount(): void
    {
        $output = $this->driver->export(
            ['id'],
            [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
        );

        /** @var array{total: int} $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(3, $decoded['total']);
    }

    #[Test]
    public function exportHandlesMissingColumnValues(): void
    {
        $output = $this->driver->export(
            ['id', 'name'],
            [
                ['id' => 1],
            ],
        );

        /** @var array{data: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertNull($decoded['data'][0]['name']);
    }

    #[Test]
    public function exportHandlesEmptyRows(): void
    {
        $output = $this->driver->export(['id', 'name'], []);

        /** @var array{data: list<array<string, mixed>>, total: int} $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $decoded['data']);
        self::assertSame(0, $decoded['total']);
    }

    #[Test]
    public function exportUsesUnescapedSlashes(): void
    {
        $output = $this->driver->export(
            ['url'],
            [
                ['url' => 'https://example.com/path'],
            ],
        );

        self::assertStringContainsString('https://example.com/path', $output);
        self::assertStringNotContainsString('\\/', $output);
    }

    #[Test]
    public function exportUsesUnescapedUnicode(): void
    {
        $output = $this->driver->export(
            ['name'],
            [
                ['name' => 'Muller'],
            ],
        );

        self::assertStringContainsString('Muller', $output);
    }
}
