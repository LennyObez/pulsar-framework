<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Config\IntegrityPolicyMode;
use Pulsar\Deploy\Check\IntegrityCheck;
use Pulsar\Deploy\CheckSeverity;

#[CoversClass(IntegrityCheck::class)]
final class IntegrityCheckTest extends TestCase
{
    #[Test]
    public function it_returns_name(): void
    {
        $check = new IntegrityCheck($this->buildConfig(enabled: true));

        self::assertSame('integrity', $check->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        $check = new IntegrityCheck($this->buildConfig(enabled: true));

        self::assertSame('Validates file integrity verification is enabled', $check->getDescription());
    }

    #[Test]
    public function it_passes_when_integrity_is_enabled(): void
    {
        $check = new IntegrityCheck($this->buildConfig(enabled: true, mode: IntegrityPolicyMode::Strict));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('Integrity verification is enabled', $result->message);
        self::assertStringContainsString('strict', $result->message);
    }

    #[Test]
    public function it_passes_when_integrity_enabled_in_staging(): void
    {
        $check = new IntegrityCheck($this->buildConfig(enabled: true));

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_warns_when_integrity_disabled_in_production(): void
    {
        $check = new IntegrityCheck($this->buildConfig(enabled: false));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('File integrity verification is disabled', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_warns_when_integrity_disabled_in_staging(): void
    {
        $check = new IntegrityCheck($this->buildConfig(enabled: false));

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('File integrity verification is disabled', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_passes_when_integrity_disabled_in_local(): void
    {
        $check = new IntegrityCheck($this->buildConfig(enabled: false));

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('not required in local', $result->message);
    }

    #[Test]
    public function production_recommendations_mention_config_and_manifest(): void
    {
        $check = new IntegrityCheck($this->buildConfig(enabled: false));

        $result = $check->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('config/integrity.php', $joined);
        self::assertStringContainsString('php bin/pulsar optimize', $joined);
    }

    #[Test]
    public function it_includes_warn_mode_in_pass_message(): void
    {
        $check = new IntegrityCheck($this->buildConfig(enabled: true, mode: IntegrityPolicyMode::Warn));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('warn', $result->message);
    }

    private function buildConfig(bool $enabled, IntegrityPolicyMode $mode = IntegrityPolicyMode::Warn): IntegrityConfig
    {
        return new IntegrityConfig(
            enabled: $enabled,
            mode: $mode,
        );
    }
}
