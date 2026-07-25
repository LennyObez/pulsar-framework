<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\OutboxConfig;

#[CoversClass(OutboxConfig::class)]
final class OutboxConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabledAndSafe(): void
    {
        $config = new OutboxConfig();

        // Opt-in: existing deployments keep synchronous delivery.
        self::assertFalse($config->enabled);
        self::assertSame(100, $config->batchSize);
        self::assertSame(5, $config->maxPublishAttempts);
    }

    #[Test]
    public function fromArrayReadsAllFields(): void
    {
        $config = OutboxConfig::fromArray([
            'enabled' => true,
            'batch_size' => 250,
            'max_publish_attempts' => 8,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(250, $config->batchSize);
        self::assertSame(8, $config->maxPublishAttempts);
    }

    #[Test]
    public function fromArrayClampsAndDefaultsInvalidValues(): void
    {
        $config = OutboxConfig::fromArray([
            'batch_size' => 0,
            'max_publish_attempts' => 'nope',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(1, $config->batchSize);          // clamped to >= 1
        self::assertSame(5, $config->maxPublishAttempts); // non-numeric -> default
    }
}
