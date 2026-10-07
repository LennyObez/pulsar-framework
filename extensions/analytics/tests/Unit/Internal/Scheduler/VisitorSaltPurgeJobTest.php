<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Scheduler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Config\PrivacyConfig;
use Pulsar\Extension\Analytics\Contracts\VisitorSaltStoreInterface;
use Pulsar\Extension\Analytics\Internal\Scheduler\VisitorSaltPurgeJob;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;
use Pulsar\Security\Crypto\KeyProviderInterface;

use function str_repeat;

final class VisitorSaltPurgeJobTest extends TestCase
{
    private AnalyticsKeyManager $keyManager;

    protected function setUp(): void
    {
        $masterKey = $this->createStub(KeyProviderInterface::class);
        $masterKey->method('deriveSubKey')->willReturn(str_repeat('k', 32));
        $this->keyManager = new AnalyticsKeyManager($masterKey);
    }

    #[Test]
    public function purgesEverythingOlderThanTheRetentionWindow(): void
    {
        // retention 2 keeps today + yesterday: cutoff = today - 1.
        $today = $this->keyManager->utcDayNumber();

        $store = $this->createMock(VisitorSaltStoreInterface::class);
        $store->expects(self::once())
            ->method('purgeOlderThan')
            ->with($today - 1)
            ->willReturn(0);

        $config = new AnalyticsConfig(
            privacy: new PrivacyConfig(visitorSaltRetentionDays: 2),
        );

        (new VisitorSaltPurgeJob($store, $this->keyManager, $config))();
    }

    #[Test]
    public function honorsALongerRetentionWindow(): void
    {
        $today = $this->keyManager->utcDayNumber();

        $store = $this->createMock(VisitorSaltStoreInterface::class);
        $store->expects(self::once())
            ->method('purgeOlderThan')
            ->with($today - 6)
            ->willReturn(0);

        $config = new AnalyticsConfig(
            privacy: new PrivacyConfig(visitorSaltRetentionDays: 7),
        );

        (new VisitorSaltPurgeJob($store, $this->keyManager, $config))();
    }

    #[Test]
    public function floorsRetentionAtTwoDaysToProtectMidnightGrace(): void
    {
        // A misconfigured 0/1-day retention must not delete yesterday's salt,
        // or the midnight session-grace lookup breaks. The job floors at 2.
        $today = $this->keyManager->utcDayNumber();

        $store = $this->createMock(VisitorSaltStoreInterface::class);
        $store->expects(self::once())
            ->method('purgeOlderThan')
            ->with($today - 1)
            ->willReturn(0);

        $config = new AnalyticsConfig(
            privacy: new PrivacyConfig(visitorSaltRetentionDays: 0),
        );

        (new VisitorSaltPurgeJob($store, $this->keyManager, $config))();
    }
}
