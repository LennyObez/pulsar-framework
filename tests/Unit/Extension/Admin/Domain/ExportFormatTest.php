<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ExportFormat;

#[CoversClass(ExportFormat::class)]
final class ExportFormatTest extends TestCase
{
    #[Test]
    public function csvValue(): void
    {
        self::assertSame('csv', ExportFormat::Csv->value);
    }

    #[Test]
    public function jsonValue(): void
    {
        self::assertSame('json', ExportFormat::Json->value);
    }

    #[Test]
    public function allCasesArePresent(): void
    {
        self::assertCount(2, ExportFormat::cases());
    }
}
