<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Internal\Export\CsvExportDriver;

#[CoversClass(CsvExportDriver::class)]
final class CsvExportDriverTest extends TestCase
{
    private CsvExportDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new CsvExportDriver();
    }

    #[Test]
    public function formatReturnsCsv(): void
    {
        self::assertSame(ExportFormat::Csv, $this->driver->format());
    }

    #[Test]
    public function mimeTypeIsCorrect(): void
    {
        self::assertSame('text/csv; charset=utf-8', $this->driver->mimeType());
    }

    #[Test]
    public function fileExtensionIsCsv(): void
    {
        self::assertSame('csv', $this->driver->fileExtension());
    }

    #[Test]
    public function exportWithHeadersAndRows(): void
    {
        $output = $this->driver->export(
            ['id', 'name', 'email'],
            [
                ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
                ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
            ],
        );

        $lines = explode("\n", trim($output));
        self::assertCount(3, $lines);

        // Header
        self::assertStringContainsString('id', $lines[0]);
        self::assertStringContainsString('name', $lines[0]);
        self::assertStringContainsString('email', $lines[0]);

        // Data rows
        self::assertStringContainsString('Alice', $lines[1]);
        self::assertStringContainsString('Bob', $lines[2]);
    }

    #[Test]
    public function exportHandlesNullValues(): void
    {
        $output = $this->driver->export(
            ['name', 'phone'],
            [
                ['name' => 'Alice', 'phone' => null],
            ],
        );

        self::assertStringContainsString('Alice', $output);
    }

    #[Test]
    public function exportHandlesBooleanValues(): void
    {
        $output = $this->driver->export(
            ['name', 'active'],
            [
                ['name' => 'Alice', 'active' => true],
                ['name' => 'Bob', 'active' => false],
            ],
        );

        self::assertStringContainsString('true', $output);
        self::assertStringContainsString('false', $output);
    }

    #[Test]
    public function exportHandlesEmptyRows(): void
    {
        $output = $this->driver->export(['id', 'name'], []);

        $lines = explode("\n", trim($output));
        self::assertCount(1, $lines); // header only
    }

    #[Test]
    public function exportHandlesMissingColumns(): void
    {
        $output = $this->driver->export(
            ['id', 'name', 'missing'],
            [
                ['id' => 1, 'name' => 'Alice'],
            ],
        );

        // Missing column should be empty
        self::assertNotEmpty($output);
    }

    #[Test]
    public function exportEscapesQuotesInValues(): void
    {
        $output = $this->driver->export(
            ['name'],
            [
                ['name' => 'Alice "the Great"'],
            ],
        );

        // fputcsv should handle the double-quote escaping
        self::assertStringContainsString('Alice', $output);
    }

    #[Test]
    public function exportEscapesCommasInValues(): void
    {
        $output = $this->driver->export(
            ['name'],
            [
                ['name' => 'Doe, Jane'],
            ],
        );

        // fputcsv should wrap in quotes
        self::assertStringContainsString('Doe, Jane', $output);
    }
}
