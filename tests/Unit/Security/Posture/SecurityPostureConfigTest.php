<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Posture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Security\Posture\SecurityPostureConfig;
use Pulsar\Security\Posture\SecurityPostureStatus;

#[CoversClass(SecurityPostureConfig::class)]
final class SecurityPostureConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PULSAR_SECURITY_POSTURE_ENFORCE');
        putenv('PULSAR_SECURITY_POSTURE_STRICT');
        putenv('PULSAR_SECURITY_POSTURE_LOG_AT_BOOT');
        putenv('APP_ENV');
    }

    #[Test]
    public function logAtBootDefaultsOnOutsideProductionAndOffInProduction(): void
    {
        // Posture is a config invariant; under PHP-FPM (boot==request) logging it
        // every boot in production floods the log, so it is off there by default.
        putenv('APP_ENV=local');
        self::assertTrue(SecurityPostureConfig::fromEnvironment(Environment::load())->logAtBoot);

        putenv('APP_ENV=production');
        self::assertFalse(SecurityPostureConfig::fromEnvironment(Environment::load())->logAtBoot);
    }

    #[Test]
    public function logAtBootExplicitEnvOverridesTheEnvironmentDefault(): void
    {
        putenv('APP_ENV=production');
        putenv('PULSAR_SECURITY_POSTURE_LOG_AT_BOOT=true');
        self::assertTrue(SecurityPostureConfig::fromEnvironment(Environment::load())->logAtBoot);

        putenv('APP_ENV=local');
        putenv('PULSAR_SECURITY_POSTURE_LOG_AT_BOOT=false');
        self::assertFalse(SecurityPostureConfig::fromEnvironment(Environment::load())->logAtBoot);
    }

    #[Test]
    public function defaultsAreBcSafe(): void
    {
        $config = SecurityPostureConfig::fromEnvironment(Environment::load());

        self::assertFalse($config->enforce, 'Enforcement must be opt-in (off by default)');
        self::assertTrue($config->blocks(SecurityPostureStatus::Fail));
        self::assertFalse($config->blocks(SecurityPostureStatus::Degraded));
    }

    #[Test]
    public function enforceFlagIsReadFromEnvironment(): void
    {
        putenv('PULSAR_SECURITY_POSTURE_ENFORCE=true');

        self::assertTrue(SecurityPostureConfig::fromEnvironment(Environment::load())->enforce);
    }

    #[Test]
    public function strictModeBlocksOnDegraded(): void
    {
        putenv('PULSAR_SECURITY_POSTURE_ENFORCE=1');
        putenv('PULSAR_SECURITY_POSTURE_STRICT=1');

        $config = SecurityPostureConfig::fromEnvironment(Environment::load());

        self::assertTrue($config->enforce);
        self::assertTrue($config->blocks(SecurityPostureStatus::Fail));
        self::assertTrue($config->blocks(SecurityPostureStatus::Degraded));
        self::assertFalse($config->blocks(SecurityPostureStatus::Ok));
    }
}
