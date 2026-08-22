<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Monitor\MonitorConfig;

#[CoversClass(MonitorConfig::class)]
final class MonitorConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = MonitorConfig::fromArray([]);

        self::assertSame(1000, $config->slowQueryThresholdMs);
        self::assertFalse($config->logRawBindings);
        self::assertTrue($config->requireEnvironmentConfirmation);
        self::assertSame([], $config->piiColumns);
        self::assertFalse($config->enabled, 'monitoring is opt-in (off by default)');
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = MonitorConfig::fromArray([
            'slow_query_threshold_ms' => 500,
            'log_raw_bindings' => true,
            'require_environment_confirmation' => false,
            'pii_columns' => ['email', 'ssn', 'phone'],
            'enabled' => true,
        ]);

        self::assertSame(500, $config->slowQueryThresholdMs);
        self::assertTrue($config->logRawBindings);
        self::assertFalse($config->requireEnvironmentConfirmation);
        self::assertSame(['email', 'ssn', 'phone'], $config->piiColumns);
        self::assertTrue($config->enabled);
    }
}
