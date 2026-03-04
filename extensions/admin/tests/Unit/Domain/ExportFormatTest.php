<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use ValueError;

#[CoversClass(ExportFormat::class)]
final class ExportFormatTest extends TestCase
{
    #[Test]
    public function csv_has_correct_value(): void
    {
        self::assertSame('csv', ExportFormat::Csv->value);
    }

    #[Test]
    public function json_has_correct_value(): void
    {
        self::assertSame('json', ExportFormat::Json->value);
    }

    #[Test]
    public function cases_returns_all_formats(): void
    {
        $cases = ExportFormat::cases();

        self::assertCount(2, $cases);
        self::assertContains(ExportFormat::Csv, $cases);
        self::assertContains(ExportFormat::Json, $cases);
    }

    #[Test]
    public function from_creates_valid_format(): void
    {
        self::assertSame(ExportFormat::Csv, ExportFormat::from('csv'));
        self::assertSame(ExportFormat::Json, ExportFormat::from('json'));
    }

    #[Test]
    public function from_throws_for_unknown_format(): void
    {
        $this->expectException(ValueError::class);

        ExportFormat::from('xml');
    }

    #[Test]
    public function try_from_returns_null_for_unknown(): void
    {
        self::assertNull(ExportFormat::tryFrom('xml'));
    }
}
