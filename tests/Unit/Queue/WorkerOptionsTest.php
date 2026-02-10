<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;
use Pulsar\Queue\WorkerOptions;

#[CoversClass(WorkerOptions::class)]
final class WorkerOptionsTest extends TestCase
{
    #[Test]
    public function it_uses_default_values(): void
    {
        $options = new WorkerOptions();

        self::assertSame(1000, $options->maxJobs);
        self::assertSame(256, $options->maxMemoryMb);
        self::assertSame(3600, $options->timeLimitSeconds);
        self::assertSame(1000, $options->sleepMs);
    }

    #[Test]
    public function it_accepts_custom_values(): void
    {
        $options = new WorkerOptions(
            maxJobs: 500,
            maxMemoryMb: 256,
            timeLimitSeconds: 1800,
            sleepMs: 500,
        );

        self::assertSame(500, $options->maxJobs);
        self::assertSame(256, $options->maxMemoryMb);
        self::assertSame(1800, $options->timeLimitSeconds);
        self::assertSame(500, $options->sleepMs);
    }

    #[Test]
    public function it_creates_from_queue_config(): void
    {
        $config = new QueueConfig(
            driver: QueueDriverType::Memory,
            workerMaxJobs: 200,
            workerMaxMemoryMb: 64,
            workerTimeLimitSeconds: 900,
            workerSleepMs: 250,
        );

        $options = WorkerOptions::fromConfig($config);

        self::assertSame(200, $options->maxJobs);
        self::assertSame(64, $options->maxMemoryMb);
        self::assertSame(900, $options->timeLimitSeconds);
        self::assertSame(250, $options->sleepMs);
    }

    #[Test]
    public function it_creates_from_default_queue_config(): void
    {
        $config = new QueueConfig();

        $options = WorkerOptions::fromConfig($config);

        self::assertSame(1000, $options->maxJobs);
        self::assertSame(256, $options->maxMemoryMb);
        self::assertSame(3600, $options->timeLimitSeconds);
        self::assertSame(1000, $options->sleepMs);
    }

    #[Test]
    public function it_accepts_zero_max_jobs(): void
    {
        $options = new WorkerOptions(maxJobs: 0);

        self::assertSame(0, $options->maxJobs);
    }

    #[Test]
    public function it_accepts_zero_time_limit(): void
    {
        $options = new WorkerOptions(timeLimitSeconds: 0);

        self::assertSame(0, $options->timeLimitSeconds);
    }
}
