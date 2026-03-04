<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Metered;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Metered\UsageRecord;
use Pulsar\Extension\Payments\Metered\UsageSummary;

final class UsageRecordTest extends TestCase
{
    #[Test]
    public function usageRecordStoresAllProperties(): void
    {
        $record = new UsageRecord(
            id: 'ur-1',
            subscriptionId: 'sub-1',
            metricKey: 'api_calls',
            quantity: 150,
            idempotencyKey: 'idem-abc',
        );

        self::assertSame('ur-1', $record->id);
        self::assertSame('sub-1', $record->subscriptionId);
        self::assertSame('api_calls', $record->metricKey);
        self::assertSame(150, $record->quantity);
        self::assertSame('idem-abc', $record->idempotencyKey);
    }

    #[Test]
    public function usageRecordDefaultsToEmptyIdempotencyKey(): void
    {
        $record = new UsageRecord(
            id: 'ur-2',
            subscriptionId: 'sub-1',
            metricKey: 'storage_gb',
            quantity: 5,
        );

        self::assertSame('', $record->idempotencyKey);
    }

    #[Test]
    public function usageSummaryTracksOverage(): void
    {
        $summary = new UsageSummary(
            subscriptionId: 'sub-1',
            metricKey: 'api_calls',
            totalQuantity: 1500,
            totalCost: Money::of(5000, Currency::USD),
            includedQuantity: 1000,
            overageQuantity: 500,
        );

        self::assertTrue($summary->hasOverage());
        self::assertSame(1500, $summary->totalQuantity);
        self::assertSame(1000, $summary->includedQuantity);
        self::assertSame(500, $summary->overageQuantity);
        self::assertSame(5000, $summary->totalCost->amount);
    }

    #[Test]
    public function usageSummaryWithinIncludedHasNoOverage(): void
    {
        $summary = new UsageSummary(
            subscriptionId: 'sub-1',
            metricKey: 'api_calls',
            totalQuantity: 500,
            totalCost: Money::zero(Currency::USD),
            includedQuantity: 1000,
            overageQuantity: 0,
        );

        self::assertFalse($summary->hasOverage());
        self::assertTrue($summary->totalCost->isZero());
    }
}
