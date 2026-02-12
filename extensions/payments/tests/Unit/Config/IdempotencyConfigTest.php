<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\IdempotencyConfig;

final class IdempotencyConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = IdempotencyConfig::fromArray([
            'ttl_seconds' => 7200,
            'store' => 'redis',
            'max_key_length' => 128,
        ]);

        self::assertSame(7200, $config->ttlSeconds);
        self::assertSame('redis', $config->store);
        self::assertSame(128, $config->maxKeyLength);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = IdempotencyConfig::fromArray([]);

        self::assertSame(86400, $config->ttlSeconds);
        self::assertSame('memory', $config->store);
        self::assertSame(256, $config->maxKeyLength);
    }
}
