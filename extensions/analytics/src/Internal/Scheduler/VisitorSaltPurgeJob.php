<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Scheduler;

use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Contracts\VisitorSaltStoreInterface;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;

use function max;

/**
 * Daily job that destroys expired visitor salts, sealing the forward-secrecy
 * guarantee.
 *
 * Every salt older than the retention window is deleted. Once a day's salt is
 * gone, that day's visitor hashes can no longer be recomputed from IP +
 * user-agent — even with the application master key — so the historical data
 * is irreversibly anonymized. The window is floored at two days so today and
 * yesterday always survive for the midnight session-grace lookup.
 */
#[Internal(reason: 'Scheduled visitor-salt purge job')]
final readonly class VisitorSaltPurgeJob
{
    /** Never retain fewer than today + yesterday, or midnight grace breaks. */
    private const int MIN_RETENTION_DAYS = 2;

    public function __construct(
        private VisitorSaltStoreInterface $saltStore,
        private AnalyticsKeyManager $keyManager,
        private AnalyticsConfig $config,
    ) {}

    public function __invoke(): void
    {
        $retentionDays = max(self::MIN_RETENTION_DAYS, $this->config->privacy->visitorSaltRetentionDays);
        $today = $this->keyManager->utcDayNumber();

        // Keep $retentionDays days including today: today, today-1, ...,
        // today-(retentionDays-1). Everything strictly older is purged.
        $cutoff = $today - ($retentionDays - 1);

        $this->saltStore->purgeOlderThan($cutoff);
    }
}
