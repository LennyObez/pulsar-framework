<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Dashboard\CacheStatsProviderInterface;
use Pulsar\Extension\Cms\Dashboard\QueueStatsProviderInterface;
use Pulsar\Extension\Cms\Dashboard\SystemHealthWidget;
use Pulsar\Storage\StorageAdapterInterface;
use Pulsar\Storage\StorageObject;

#[CoversClass(SystemHealthWidget::class)]
final class SystemHealthWidgetTest extends TestCase
{
    #[Test]
    public function test_get_name_returns_system_health(): void
    {
        $widget = $this->createWidget();

        self::assertSame('system_health', $widget->getName());
    }

    #[Test]
    public function test_get_template_returns_expected_path(): void
    {
        $widget = $this->createWidget();

        self::assertSame('dashboard/widgets/system-health', $widget->getTemplate());
    }

    #[Test]
    public function test_get_data_healthy_with_good_metrics(): void
    {
        $widget = $this->createWidget(
            cacheHitRate: 0.95,
            pendingJobs: 3,
            failedJobs: 0,
            storageObjects: ['file1.jpg', 'file2.png'],
        );

        $data = $widget->getData();

        self::assertSame(0.95, $data['cache_hit_rate']);
        self::assertSame(3, $data['queue_depth']);
        self::assertSame(0, $data['failed_job_count']);
        self::assertSame(2, $data['storage_object_count']);
        self::assertSame('healthy', $data['health_status']);
    }

    #[Test]
    public function test_get_data_unhealthy_when_failed_jobs(): void
    {
        $widget = $this->createWidget(
            cacheHitRate: 0.95,
            failedJobs: 5,
        );

        $data = $widget->getData();

        self::assertSame('unhealthy', $data['health_status']);
    }

    #[Test]
    public function test_get_data_unhealthy_when_cache_hit_rate_very_low(): void
    {
        $widget = $this->createWidget(
            cacheHitRate: 0.3,
            failedJobs: 0,
        );

        $data = $widget->getData();

        self::assertSame('unhealthy', $data['health_status']);
    }

    #[Test]
    public function test_get_data_degraded_when_cache_hit_rate_moderate(): void
    {
        $widget = $this->createWidget(
            cacheHitRate: 0.7,
            failedJobs: 0,
        );

        $data = $widget->getData();

        self::assertSame('degraded', $data['health_status']);
    }

    /**
     * @param list<string> $storageObjects
     */
    private function createWidget(
        float $cacheHitRate = 0.9,
        int $pendingJobs = 0,
        int $failedJobs = 0,
        array $storageObjects = [],
    ): SystemHealthWidget {
        $queueStats = $this->createStub(QueueStatsProviderInterface::class);
        $queueStats->method('getPendingCount')->willReturn($pendingJobs);
        $queueStats->method('getFailedCount')->willReturn($failedJobs);

        $objects = [];
        foreach ($storageObjects as $name) {
            $objects[] = new StorageObject(key: $name, size: 1024, lastModified: 1708300000);
        }

        $storage = $this->createStub(StorageAdapterInterface::class);
        $storage->method('list')->willReturn($objects);

        $cacheStatsProvider = $this->createStub(CacheStatsProviderInterface::class);
        $cacheStatsProvider->method('getHitRate')->willReturn($cacheHitRate);

        return new SystemHealthWidget($queueStats, $storage, $cacheStatsProvider);
    }
}
