<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\KeyLifecycleConfig;

#[CoversClass(KeyLifecycleConfig::class)]
final class KeyLifecycleConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new KeyLifecycleConfig();

        self::assertSame(7776000, $config->defaultRotationIntervalSeconds);
        self::assertSame(86400, $config->defaultGracePeriodSeconds);
        self::assertSame([30, 14, 7, 1], $config->certificateWarningDays);
        self::assertSame(1, $config->deployBlockDays);
    }

    public function testFromArrayWithDefaults(): void
    {
        $config = KeyLifecycleConfig::fromArray([]);

        self::assertSame(7776000, $config->defaultRotationIntervalSeconds);
        self::assertSame(86400, $config->defaultGracePeriodSeconds);
        self::assertSame([30, 14, 7, 1], $config->certificateWarningDays);
        self::assertSame(1, $config->deployBlockDays);
    }

    public function testFromArrayWithCustomValues(): void
    {
        $config = KeyLifecycleConfig::fromArray([
            'default_rotation_interval_seconds' => 2592000,
            'default_grace_period_seconds' => 3600,
            'certificate_warning_days' => [60, 30, 7],
            'deploy_block_days' => 3,
        ]);

        self::assertSame(2592000, $config->defaultRotationIntervalSeconds);
        self::assertSame(3600, $config->defaultGracePeriodSeconds);
        self::assertSame([60, 30, 7], $config->certificateWarningDays);
        self::assertSame(3, $config->deployBlockDays);
    }

    public function testFromArrayPartialOverride(): void
    {
        $config = KeyLifecycleConfig::fromArray([
            'deploy_block_days' => 5,
        ]);

        self::assertSame(7776000, $config->defaultRotationIntervalSeconds);
        self::assertSame(5, $config->deployBlockDays);
    }
}
