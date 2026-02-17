<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\BatchConfig;

#[CoversClass(BatchConfig::class)]
final class BatchConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new BatchConfig();

        self::assertSame(512, $config->maxBatchSize);
        self::assertSame(2048, $config->maxQueueSize);
    }

    #[Test]
    public function customValues(): void
    {
        $config = new BatchConfig(maxBatchSize: 100, maxQueueSize: 500);

        self::assertSame(100, $config->maxBatchSize);
        self::assertSame(500, $config->maxQueueSize);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = BatchConfig::fromArray([
            'max_batch_size' => 256,
            'max_queue_size' => 1024,
        ]);

        self::assertSame(256, $config->maxBatchSize);
        self::assertSame(1024, $config->maxQueueSize);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = BatchConfig::fromArray([]);

        self::assertSame(512, $config->maxBatchSize);
        self::assertSame(2048, $config->maxQueueSize);
    }

    #[Test]
    public function fromArrayWithInvalidTypesUsesDefaults(): void
    {
        $config = BatchConfig::fromArray([
            'max_batch_size' => 'not-an-int',
            'max_queue_size' => false,
        ]);

        self::assertSame(512, $config->maxBatchSize);
        self::assertSame(2048, $config->maxQueueSize);
    }
}
