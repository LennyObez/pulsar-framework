<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\BillingCycle;

final class BillingCycleTest extends TestCase
{
    #[Test]
    #[DataProvider('nextDateProvider')]
    public function nextDateReturnsCorrectDate(BillingCycle $cycle, string $from, string $expected): void
    {
        $fromDate = new DateTimeImmutable($from);
        $result = $cycle->nextDate($fromDate);

        self::assertSame($expected, $result->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{BillingCycle, string, string}>
     */
    public static function nextDateProvider(): iterable
    {
        yield 'weekly' => [BillingCycle::Weekly, '2026-01-01', '2026-01-08'];
        yield 'monthly' => [BillingCycle::Monthly, '2026-01-15', '2026-02-15'];
        yield 'quarterly' => [BillingCycle::Quarterly, '2026-01-01', '2026-04-01'];
        yield 'semi-annual' => [BillingCycle::SemiAnnual, '2026-01-01', '2026-07-01'];
        yield 'annual' => [BillingCycle::Annual, '2026-03-01', '2027-03-01'];
    }

    #[Test]
    #[DataProvider('approximateDaysProvider')]
    public function approximateDaysReturnsExpectedValue(BillingCycle $cycle, int $expected): void
    {
        self::assertSame($expected, $cycle->approximateDays());
    }

    /**
     * @return iterable<string, array{BillingCycle, int}>
     */
    public static function approximateDaysProvider(): iterable
    {
        yield 'weekly' => [BillingCycle::Weekly, 7];
        yield 'monthly' => [BillingCycle::Monthly, 30];
        yield 'quarterly' => [BillingCycle::Quarterly, 90];
        yield 'semi-annual' => [BillingCycle::SemiAnnual, 182];
        yield 'annual' => [BillingCycle::Annual, 365];
    }

    #[Test]
    public function allCasesHaveStringValues(): void
    {
        foreach (BillingCycle::cases() as $cycle) {
            self::assertNotEmpty($cycle->value);
        }
    }

    #[Test]
    public function nextDatePreservesTimeComponent(): void
    {
        $from = new DateTimeImmutable('2026-01-15 14:30:00');
        $result = BillingCycle::Monthly->nextDate($from);

        self::assertSame('14:30:00', $result->format('H:i:s'));
    }
}
