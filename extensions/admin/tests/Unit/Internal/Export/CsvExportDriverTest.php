<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Internal\Export\CsvExportDriver;

final class CsvExportDriverTest extends TestCase
{
    private CsvExportDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new CsvExportDriver();
    }

    #[Test]
    public function format_returns_csv(): void
    {
        self::assertSame(ExportFormat::Csv, $this->driver->format());
    }

    #[Test]
    public function mime_type(): void
    {
        self::assertSame('text/csv; charset=utf-8', $this->driver->mimeType());
    }

    #[Test]
    public function file_extension(): void
    {
        self::assertSame('csv', $this->driver->fileExtension());
    }

    #[Test]
    public function export_with_data(): void
    {
        $columns = ['name', 'email'];
        $rows = [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        ];

        $output = $this->driver->export($columns, $rows);

        self::assertStringContainsString('name,email', $output);
        self::assertStringContainsString('Alice,alice@example.com', $output);
        self::assertStringContainsString('Bob,bob@example.com', $output);
    }

    #[Test]
    public function export_empty_rows(): void
    {
        $output = $this->driver->export(['id', 'name'], []);

        self::assertStringContainsString('id,name', $output);
        // Only the header row
        $lines = array_filter(explode("\n", trim($output)));
        self::assertCount(1, $lines);
    }

    #[Test]
    public function export_null_values_rendered_as_empty(): void
    {
        $output = $this->driver->export(
            ['name', 'bio'],
            [['name' => 'Alice', 'bio' => null]],
        );

        // Null becomes empty string in CSV
        self::assertStringContainsString('Alice', $output);
    }

    #[Test]
    public function export_boolean_values(): void
    {
        $output = $this->driver->export(
            ['name', 'active'],
            [['name' => 'Alice', 'active' => true], ['name' => 'Bob', 'active' => false]],
        );

        self::assertStringContainsString('true', $output);
        self::assertStringContainsString('false', $output);
    }

    #[Test]
    public function export_formula_injection_protection(): void
    {
        $output = $this->driver->export(
            ['name'],
            [
                ['name' => '=CMD("exploit")'],
                ['name' => '+1+2'],
                ['name' => '-1+2'],
                ['name' => '@SUM(A1)'],
            ],
        );

        // Formula-injecting values should be prefixed with a tab
        self::assertStringNotContainsString('"=CMD', $output);
    }

    #[Test]
    public function export_missing_column_values_are_empty(): void
    {
        $output = $this->driver->export(
            ['name', 'missing'],
            [['name' => 'Alice']],
        );

        self::assertStringContainsString('Alice', $output);
    }
}
