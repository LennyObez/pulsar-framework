<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\StepUp;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\StepUp\StepUpConfig;

#[CoversClass(StepUpConfig::class)]
final class StepUpConfigTest extends TestCase
{
    #[Test]
    public function defaults_are_sensible(): void
    {
        $config = new StepUpConfig();

        self::assertSame(5, $config->maxAttempts);
        self::assertSame(0, $config->cooldownSeconds);
        self::assertSame(900, $config->lockoutSeconds);
        self::assertSame(3600, $config->windowSeconds);
    }

    #[Test]
    public function accepts_custom_values(): void
    {
        $config = new StepUpConfig(
            maxAttempts: 3,
            cooldownSeconds: 30,
            lockoutSeconds: 1800,
            windowSeconds: 600,
        );

        self::assertSame(3, $config->maxAttempts);
        self::assertSame(30, $config->cooldownSeconds);
        self::assertSame(1800, $config->lockoutSeconds);
        self::assertSame(600, $config->windowSeconds);
    }

    #[Test]
    public function rejects_zero_max_attempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxAttempts');

        new StepUpConfig(maxAttempts: 0);
    }

    #[Test]
    public function rejects_negative_max_attempts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StepUpConfig(maxAttempts: -1);
    }

    #[Test]
    public function rejects_negative_cooldown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cooldownSeconds');

        new StepUpConfig(cooldownSeconds: -1);
    }

    #[Test]
    public function rejects_negative_lockout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lockoutSeconds');

        new StepUpConfig(lockoutSeconds: -1);
    }

    #[Test]
    public function rejects_zero_window(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('windowSeconds');

        new StepUpConfig(windowSeconds: 0);
    }

    #[Test]
    public function allows_zero_cooldown(): void
    {
        $config = new StepUpConfig(cooldownSeconds: 0);

        self::assertSame(0, $config->cooldownSeconds);
    }

    #[Test]
    public function allows_zero_lockout(): void
    {
        $config = new StepUpConfig(lockoutSeconds: 0);

        self::assertSame(0, $config->lockoutSeconds);
    }

    #[Test]
    public function from_array_uses_defaults_for_missing_keys(): void
    {
        $config = StepUpConfig::fromArray([]);

        self::assertSame(5, $config->maxAttempts);
        self::assertSame(0, $config->cooldownSeconds);
        self::assertSame(900, $config->lockoutSeconds);
        self::assertSame(3600, $config->windowSeconds);
    }

    #[Test]
    public function from_array_uses_provided_values(): void
    {
        $config = StepUpConfig::fromArray([
            'max_attempts' => 10,
            'cooldown_seconds' => 60,
            'lockout_seconds' => 300,
            'window_seconds' => 7200,
        ]);

        self::assertSame(10, $config->maxAttempts);
        self::assertSame(60, $config->cooldownSeconds);
        self::assertSame(300, $config->lockoutSeconds);
        self::assertSame(7200, $config->windowSeconds);
    }

    #[Test]
    public function from_array_ignores_non_int_values(): void
    {
        $config = StepUpConfig::fromArray([
            'max_attempts' => 'not_int',
            'cooldown_seconds' => 3.14,
        ]);

        self::assertSame(5, $config->maxAttempts);
        self::assertSame(0, $config->cooldownSeconds);
    }
}
