<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Domain\VelocityWindow;

final class VelocityWindowTest extends TestCase
{
    #[Test]
    public function exceedsCountThresholdReturnsTrueWhenAtOrAboveLimit(): void
    {
        $window = new VelocityWindow(
            identityId: 'user_001',
            windowSeconds: 3600,
            transactionCount: 10,
            totalAmountMinorUnits: 5000,
            currency: 'EUR',
        );

        self::assertTrue($window->exceedsCountThreshold(10));
        self::assertTrue($window->exceedsCountThreshold(5));
    }

    #[Test]
    public function exceedsCountThresholdReturnsFalseBelowLimit(): void
    {
        $window = new VelocityWindow(
            identityId: 'user_001',
            windowSeconds: 3600,
            transactionCount: 3,
            totalAmountMinorUnits: 5000,
            currency: 'EUR',
        );

        self::assertFalse($window->exceedsCountThreshold(10));
    }

    #[Test]
    public function exceedsAmountThresholdReturnsTrueWhenAtOrAboveLimit(): void
    {
        $window = new VelocityWindow(
            identityId: 'user_001',
            windowSeconds: 3600,
            transactionCount: 1,
            totalAmountMinorUnits: 60000,
            currency: 'EUR',
        );

        self::assertTrue($window->exceedsAmountThreshold(50000));
    }

    #[Test]
    public function exceedsAmountThresholdReturnsFalseBelowLimit(): void
    {
        $window = new VelocityWindow(
            identityId: 'user_001',
            windowSeconds: 3600,
            transactionCount: 1,
            totalAmountMinorUnits: 1000,
            currency: 'EUR',
        );

        self::assertFalse($window->exceedsAmountThreshold(50000));
    }
}
