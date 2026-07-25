<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus;

use DateTimeImmutable;
use NoDiscard;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Extension\HealthStatus\Domain\StatusPill;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use Throwable;

use function sprintf;

/**
 * Produces the site-wide {@see StatusPill} for a footer/header indicator from the
 * worst recorded health status over a recent window.
 *
 * The result is cached for a short interval (so a footer on every page does not
 * re-query the history store) and **fails open to a neutral `unknown`** on any
 * error or absence of data — it never reports a false "operational". A store or
 * cache exception degrades to `unknown`, never to a green pill.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StatusPillProvider
{
    private const string CACHE_KEY = 'health_status.footer_pill';

    public function __construct(
        private HealthHistoryStoreInterface $store,
        private ?CacheInterface $cache = null,
        private int $windowHours = 24,
        private int $cacheTtlSeconds = 60,
    ) {}

    /**
     * The current pill: the worst overall status recorded in the last
     * `windowHours`, or `unknown` when there is no data or anything fails.
     */
    #[NoDiscard]
    public function pill(): StatusPill
    {
        if ($this->cache !== null) {
            try {
                /** @var mixed $cached */
                $cached = $this->cache->get(self::CACHE_KEY);

                if ($cached !== null) {
                    return StatusPill::fromArray($cached);
                }
            } catch (Throwable) {
                // Cache miss/error: recompute below.
            }
        }

        $pill = $this->compute();

        if ($this->cache !== null) {
            try {
                $this->cache->set(self::CACHE_KEY, $pill->toArray(), $this->cacheTtlSeconds);
            } catch (Throwable) {
                // A failed cache write must not break rendering.
            }
        }

        return $pill;
    }

    private function compute(): StatusPill
    {
        try {
            $to = new DateTimeImmutable('now');
            $from = $to->modify(sprintf('-%d hours', $this->windowHours));
            $snapshots = $this->store->snapshotsBetween($from, $to);
        } catch (Throwable) {
            // Store/DB error: neutral, never a false green.
            return StatusPill::unknown();
        }

        if ($snapshots === []) {
            return StatusPill::unknown();
        }

        $worst = HealthStatus::Healthy;

        foreach ($snapshots as $snapshot) {
            if (self::rank($snapshot->overallStatus) > self::rank($worst)) {
                $worst = $snapshot->overallStatus;
            }
        }

        return StatusPill::fromHealthStatus($worst);
    }

    private static function rank(HealthStatus $status): int
    {
        return match ($status) {
            HealthStatus::Healthy => 0,
            HealthStatus::Degraded => 1,
            HealthStatus::Unhealthy => 2,
        };
    }
}
