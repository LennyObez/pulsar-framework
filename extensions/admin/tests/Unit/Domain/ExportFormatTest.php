<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use ValueError;

#[CoversNothing]
final class ExportFormatTest extends TestCase
{
    #[Test]
    public function csvHasCorrectValue(): void
    {
        self::assertSame('csv', ExportFormat::Csv->value);
    }

    #[Test]
    public function jsonHasCorrectValue(): void
    {
        self::assertSame('json', ExportFormat::Json->value);
    }

    #[Test]
    public function casesReturnsAllFormats(): void
    {
        $cases = ExportFormat::cases();

        self::assertCount(2, $cases);
        self::assertContains(ExportFormat::Csv, $cases);
        self::assertContains(ExportFormat::Json, $cases);
    }

    #[Test]
    public function fromCreatesValidFormat(): void
    {
        self::assertSame(ExportFormat::Csv, ExportFormat::from('csv'));
        self::assertSame(ExportFormat::Json, ExportFormat::from('json'));
    }

    #[Test]
    public function fromThrowsForUnknownFormat(): void
    {
        $this->expectException(ValueError::class);

        ExportFormat::from('xml');
    }

    #[Test]
    public function tryFromReturnsNullForUnknown(): void
    {
        self::assertNull(ExportFormat::tryFrom('xml'));
    }
}
