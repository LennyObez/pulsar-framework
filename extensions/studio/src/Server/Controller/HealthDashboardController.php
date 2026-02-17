<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Internal\Diagnostics\MemoryTracker;
use Pulsar\Http\Message\Response;

use function array_slice;
use function disk_free_space;
use function disk_total_space;
use function ini_get;
use function json_encode;
use function memory_get_peak_usage;
use function memory_get_usage;
use function microtime;
use function php_uname;
use function round;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_INT_SIZE;
use const PHP_OS_FAMILY;
use const PHP_VERSION;

/**
 * Handles GET /studio/console/health: system health dashboard.
 *
 * Provides real-time visibility into CPU, memory, disk, queue depth,
 * and connection pool stats. Designed for persistent runtime monitoring.
 */
#[Internal]
final readonly class HealthDashboardController
{
    use RendersStudioView;

    public function __construct(
        private EventStoreInterface $store,
        private ?MemoryTracker $memoryTracker = null,
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(ServerRequestInterface $_request): Response
    {
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $diskPath = sys_get_temp_dir();
        $diskFree = disk_free_space($diskPath);
        $diskTotal = disk_total_space($diskPath);

        // Queue depth from event store
        $queueDepth = $this->store->count(['event_type' => ['job.queued']]);
        $failedJobs = $this->store->count(['event_type' => ['job.failed']]);
        $completedJobs = $this->store->count(['event_type' => ['job.completed']]);

        // Memory tracker snapshots for trend visualization
        $memorySnapshots = [];
        $leakReport = null;

        if ($this->memoryTracker !== null) {
            $this->memoryTracker->recentSnapshots(50);
            $memorySnapshots = $this->memoryTracker->toArray();
            $memorySnapshots = array_slice($memorySnapshots, -50);
            $leak = $this->memoryTracker->detectLeak();

            if ($leak !== null) {
                $leakReport = $leak->toArray();
            }
        }

        $dataJson = json_encode([
            'system' => [
                'php_version' => PHP_VERSION,
                'os' => PHP_OS_FAMILY,
                'architecture' => PHP_INT_SIZE === 8 ? 'x64' : 'x86',
                'hostname' => php_uname('n'),
                'server_time' => microtime(true),
            ],
            'memory' => [
                'usage_bytes' => $memoryUsage,
                'peak_bytes' => $memoryPeak,
                'limit' => $this->getMemoryLimit(),
            ],
            'disk' => [
                'free_bytes' => $diskFree !== false ? (int) $diskFree : 0,
                'total_bytes' => $diskTotal !== false ? (int) $diskTotal : 0,
                'used_percent' => $diskTotal !== false && $diskTotal > 0 && $diskFree !== false
                    ? round((1.0 - (float) $diskFree / (float) $diskTotal) * 100.0, 1)
                    : 0.0,
            ],
            'queue' => [
                'pending' => $queueDepth,
                'failed' => $failedJobs,
                'completed' => $completedJobs,
            ],
            'memory_snapshots' => $memorySnapshots,
            'leak_report' => $leakReport,
            'event_store' => [
                'total_events' => $this->store->count(),
                'size_bytes' => $this->store->sizeInBytes(),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $html = $this->renderStudioView('System health - Pulsar Studio', 'console/health', [
            'dataJson' => $dataJson,
        ]);

        return Response::html($html);
    }

    /**
     * Read the configured PHP memory limit.
     */
    private function getMemoryLimit(): string
    {
        $limit = ini_get('memory_limit');

        return $limit !== false ? $limit : '-1';
    }
}
