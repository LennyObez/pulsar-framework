<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Internal\Export\JsonExportDriver;

final class JsonExportDriverTest extends TestCase
{
    private JsonExportDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new JsonExportDriver();
    }

    #[Test]
    public function format_returns_json(): void
    {
        self::assertSame(ExportFormat::Json, $this->driver->format());
    }

    #[Test]
    public function mime_type(): void
    {
        self::assertSame('application/json; charset=utf-8', $this->driver->mimeType());
    }

    #[Test]
    public function file_extension(): void
    {
        self::assertSame('json', $this->driver->fileExtension());
    }

    #[Test]
    public function export_with_data(): void
    {
        $columns = ['id', 'name'];
        $rows = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $output = $this->driver->export($columns, $rows);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $decoded['total']);
        self::assertSame(['id', 'name'], $decoded['columns']);
        self::assertCount(2, $decoded['data']);
        self::assertSame(['id' => 1, 'name' => 'Alice'], $decoded['data'][0]);
        self::assertSame(['id' => 2, 'name' => 'Bob'], $decoded['data'][1]);
    }

    #[Test]
    public function export_empty_rows(): void
    {
        $output = $this->driver->export(['id'], []);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $decoded['total']);
        self::assertSame([], $decoded['data']);
        self::assertSame(['id'], $decoded['columns']);
    }

    #[Test]
    public function export_missing_column_values_are_null(): void
    {
        $output = $this->driver->export(
            ['name', 'missing'],
            [['name' => 'Alice']],
        );
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertNull($decoded['data'][0]['missing']);
    }

    #[Test]
    public function export_produces_valid_json(): void
    {
        $output = $this->driver->export(
            ['a'],
            [['a' => 'value/with/slashes'], ['a' => 'unicode: ']],
        );

        // Should not throw
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $decoded['total']);
    }
}
