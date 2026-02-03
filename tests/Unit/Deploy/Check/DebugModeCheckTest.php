<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AppConfig;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Deploy\Check\DebugModeCheck;
use Pulsar\Deploy\CheckSeverity;

#[CoversClass(DebugModeCheck::class)]
final class DebugModeCheckTest extends TestCase
{
    #[Test]
    public function it_returns_name(): void
    {
        $check = new DebugModeCheck($this->buildConfig(false));

        self::assertSame('debug-mode', $check->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        $check = new DebugModeCheck($this->buildConfig(false));

        self::assertSame('Validates debug mode is disabled in staging and production', $check->getDescription());
    }

    #[Test]
    public function it_passes_when_debug_is_disabled(): void
    {
        $check = new DebugModeCheck($this->buildConfig(false));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertSame('Debug mode is disabled', $result->message);
    }

    #[Test]
    public function it_passes_when_debug_disabled_in_staging(): void
    {
        $check = new DebugModeCheck($this->buildConfig(false));

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_errors_when_debug_enabled_in_production(): void
    {
        $check = new DebugModeCheck($this->buildConfig(true));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('Debug mode is enabled in production', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_warns_when_debug_enabled_in_staging(): void
    {
        $check = new DebugModeCheck($this->buildConfig(true));

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('Debug mode is enabled in staging', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_passes_when_debug_enabled_in_local(): void
    {
        $check = new DebugModeCheck($this->buildConfig(true));

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('acceptable in local', $result->message);
    }

    #[Test]
    public function production_error_recommendations_mention_app_debug(): void
    {
        $check = new DebugModeCheck($this->buildConfig(true));

        $result = $check->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('APP_DEBUG=false', $joined);
    }

    private function buildConfig(bool $debug): AppConfig
    {
        return new AppConfig(
            name: 'TestApp',
            mode: EnvironmentMode::Local,
            debug: $debug,
            timezone: 'UTC',
            locale: 'en',
        );
    }
}
