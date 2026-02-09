<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use Pulsar\Api\Internal;
use Pulsar\Storage\StorageAdapterInterface;

use function count;

/**
 * Dashboard widget showing system health: cache hit rate, queue depth,
 * storage usage (media disk), and failed job count.
 */
#[Internal(reason: 'CMS dashboard widget — implementation detail')]
final readonly class SystemHealthWidget implements DashboardWidgetInterface
{
    private const string MEDIA_QUEUE = 'media_derivatives';

    public function __construct(
        private QueueStatsProviderInterface $queueStats,
        private StorageAdapterInterface $mediaStorage,
        private CacheStatsProviderInterface $cacheStats,
    ) {}

    public function getName(): string
    {
        return 'system_health';
    }

    public function getData(): array
    {
        $pendingJobs = $this->queueStats->getPendingCount(self::MEDIA_QUEUE);
        $failedJobs = $this->queueStats->getFailedCount(self::MEDIA_QUEUE);
        $cacheHitRate = $this->cacheStats->getHitRate();
        $storageObjects = $this->mediaStorage->list('');

        return [
            'cache_hit_rate' => $cacheHitRate,
            'queue_depth' => $pendingJobs,
            'failed_job_count' => $failedJobs,
            'storage_object_count' => count($storageObjects),
            'health_status' => $this->computeHealthStatus($cacheHitRate, $failedJobs),
        ];
    }

    public function getTemplate(): string
    {
        return 'dashboard/widgets/system-health';
    }

    private function computeHealthStatus(float $cacheHitRate, int $failedJobs): string
    {
        if ($failedJobs > 0 || $cacheHitRate < 0.5) {
            return 'unhealthy';
        }

        if ($cacheHitRate < 0.8) {
            return 'degraded';
        }

        return 'healthy';
    }
}
