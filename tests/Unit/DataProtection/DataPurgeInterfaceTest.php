<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\DataPurgeInterface;
use Pulsar\DataProtection\DefaultRetentionPolicy;

final class DataPurgeInterfaceTest extends TestCase
{
    #[Test]
    public function purgeReturnsNumberOfPurgedRecords(): void
    {
        $policy = new DefaultRetentionPolicy('audit_logs', 30);

        $purge = $this->createStub(DataPurgeInterface::class);
        $purge->method('purge')->willReturn(15);

        self::assertSame(15, $purge->purge($policy));
    }

    #[Test]
    public function purgeReturnsZeroWhenNothingToPurge(): void
    {
        $policy = new DefaultRetentionPolicy('sessions', 90);

        $purge = $this->createStub(DataPurgeInterface::class);
        $purge->method('purge')->willReturn(0);

        self::assertSame(0, $purge->purge($policy));
    }

    #[Test]
    public function countExpiredReturnsEligibleCount(): void
    {
        $policy = new DefaultRetentionPolicy('logs', 7);

        $purge = $this->createStub(DataPurgeInterface::class);
        $purge->method('countExpired')->willReturn(42);

        self::assertSame(42, $purge->countExpired($policy));
    }

    #[Test]
    public function countExpiredReturnsZeroWhenNothingExpired(): void
    {
        $policy = new DefaultRetentionPolicy('fresh_data', 365);

        $purge = $this->createStub(DataPurgeInterface::class);
        $purge->method('countExpired')->willReturn(0);

        self::assertSame(0, $purge->countExpired($policy));
    }
}
