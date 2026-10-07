<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Internal\Monitoring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Internal\Monitoring\InMemoryVelocityTracker;

final class InMemoryVelocityTrackerTest extends TestCase
{
    #[Test]
    public function getWindowReturnsZerosForUnknownIdentity(): void
    {
        $tracker = new InMemoryVelocityTracker();

        $window = $tracker->getWindow('unknown', 3600, 'EUR');

        self::assertSame('unknown', $window->identityId);
        self::assertSame(0, $window->transactionCount);
        self::assertSame(0, $window->totalAmountMinorUnits);
        self::assertSame('EUR', $window->currency);
    }

    #[Test]
    public function recordAndGetWindowTracksTransactions(): void
    {
        $tracker = new InMemoryVelocityTracker();

        $tracker->record('user_001', 1000, 'EUR');
        $tracker->record('user_001', 2000, 'EUR');
        $tracker->record('user_001', 3000, 'EUR');

        $window = $tracker->getWindow('user_001', 3600, 'EUR');

        self::assertSame(3, $window->transactionCount);
        self::assertSame(6000, $window->totalAmountMinorUnits);
    }

    #[Test]
    public function getWindowFiltersByCurrency(): void
    {
        $tracker = new InMemoryVelocityTracker();

        $tracker->record('user_001', 1000, 'EUR');
        $tracker->record('user_001', 2000, 'USD');
        $tracker->record('user_001', 3000, 'EUR');

        $eurWindow = $tracker->getWindow('user_001', 3600, 'EUR');
        $usdWindow = $tracker->getWindow('user_001', 3600, 'USD');

        self::assertSame(2, $eurWindow->transactionCount);
        self::assertSame(4000, $eurWindow->totalAmountMinorUnits);
        self::assertSame(1, $usdWindow->transactionCount);
        self::assertSame(2000, $usdWindow->totalAmountMinorUnits);
    }

    #[Test]
    public function getWindowIsolatesIdentities(): void
    {
        $tracker = new InMemoryVelocityTracker();

        $tracker->record('user_001', 1000, 'EUR');
        $tracker->record('user_002', 5000, 'EUR');

        $window1 = $tracker->getWindow('user_001', 3600, 'EUR');
        $window2 = $tracker->getWindow('user_002', 3600, 'EUR');

        self::assertSame(1, $window1->transactionCount);
        self::assertSame(1000, $window1->totalAmountMinorUnits);
        self::assertSame(1, $window2->transactionCount);
        self::assertSame(5000, $window2->totalAmountMinorUnits);
    }
}
