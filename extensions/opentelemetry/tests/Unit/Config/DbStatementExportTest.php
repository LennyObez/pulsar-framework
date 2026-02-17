<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\DbStatementExport;

#[CoversClass(DbStatementExport::class)]
final class DbStatementExportTest extends TestCase
{
    #[Test]
    #[DataProvider('caseProvider')]
    public function backedValues(DbStatementExport $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{DbStatementExport, string}>
     */
    public static function caseProvider(): iterable
    {
        yield 'none' => [DbStatementExport::None, 'none'];
        yield 'hash' => [DbStatementExport::Hash, 'hash'];
        yield 'full' => [DbStatementExport::Full, 'full'];
    }

    #[Test]
    public function tryFromValidValue(): void
    {
        self::assertSame(DbStatementExport::Hash, DbStatementExport::tryFrom('hash'));
    }

    #[Test]
    public function tryFromInvalidValueReturnsNull(): void
    {
        self::assertNull(DbStatementExport::tryFrom('invalid'));
    }

    #[Test]
    public function allCasesAreCovered(): void
    {
        self::assertCount(3, DbStatementExport::cases());
    }
}
