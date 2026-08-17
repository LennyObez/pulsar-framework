<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\Portability;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\Portability\ExportStatus;

#[CoversNothing]
final class ExportStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{ExportStatus, string}>
     */
    public static function statusProvider(): iterable
    {
        yield 'pending' => [ExportStatus::Pending, 'pending'];
        yield 'processing' => [ExportStatus::Processing, 'processing'];
        yield 'fulfilled' => [ExportStatus::Fulfilled, 'fulfilled'];
        yield 'cancelled' => [ExportStatus::Cancelled, 'cancelled'];
        yield 'failed' => [ExportStatus::Failed, 'failed'];
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function backingValueMatchesExpected(ExportStatus $status, string $value): void
    {
        self::assertSame($value, $status->value);
    }

    #[Test]
    public function allCasesAreEnumerated(): void
    {
        self::assertCount(5, ExportStatus::cases());
    }

    #[Test]
    public function fromValidValue(): void
    {
        self::assertSame(ExportStatus::Fulfilled, ExportStatus::from('fulfilled'));
    }

    #[Test]
    public function tryFromInvalidValueReturnsNull(): void
    {
        self::assertNull(ExportStatus::tryFrom('unknown'));
    }
}
