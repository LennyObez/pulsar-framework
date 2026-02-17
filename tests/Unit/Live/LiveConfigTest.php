<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\LiveConfig;

#[CoversClass(LiveConfig::class)]
final class LiveConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = new LiveConfig();

        self::assertSame('/_live', $config->endpointPrefix);
        self::assertSame(150, $config->debounceMs);
        self::assertSame(1_048_576, $config->maxPayloadSize);
        self::assertTrue($config->enablePolling);
        self::assertSame(2000, $config->defaultPollIntervalMs);
        self::assertTrue($config->morphDom);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = LiveConfig::fromArray([
            'endpoint_prefix' => '/_components',
            'debounce_ms' => 300,
            'max_payload_size' => 2_097_152,
            'enable_polling' => false,
            'default_poll_interval_ms' => 5000,
            'morph_dom' => false,
        ]);

        self::assertSame('/_components', $config->endpointPrefix);
        self::assertSame(300, $config->debounceMs);
        self::assertSame(2_097_152, $config->maxPayloadSize);
        self::assertFalse($config->enablePolling);
        self::assertSame(5000, $config->defaultPollIntervalMs);
        self::assertFalse($config->morphDom);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = LiveConfig::fromArray([]);

        self::assertSame('/_live', $config->endpointPrefix);
        self::assertSame(150, $config->debounceMs);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $config = LiveConfig::fromArray([
            'endpoint_prefix' => 42,
            'debounce_ms' => 'fast',
            'enable_polling' => 'yes',
        ]);

        self::assertSame('/_live', $config->endpointPrefix);
        self::assertSame(150, $config->debounceMs);
        self::assertTrue($config->enablePolling);
    }
}
