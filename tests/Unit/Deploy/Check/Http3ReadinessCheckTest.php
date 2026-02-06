<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DeployConfig;
use Pulsar\Deploy\Check\Http3ReadinessCheck;
use Pulsar\Deploy\CheckSeverity;

#[CoversClass(Http3ReadinessCheck::class)]
final class Http3ReadinessCheckTest extends TestCase
{
    #[Test]
    public function it_returns_name(): void
    {
        $check = new Http3ReadinessCheck(new DeployConfig());

        self::assertSame('http3-readiness', $check->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        $check = new Http3ReadinessCheck(new DeployConfig());

        self::assertSame('Validates HTTP/3 Alt-Svc configuration when opted in', $check->getDescription());
    }

    #[Test]
    public function it_passes_when_http3_disabled(): void
    {
        $config = new DeployConfig(http3Enabled: false);
        $check = new Http3ReadinessCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('not enabled', $result->message);
        self::assertStringContainsString('opt-in', $result->message);
    }

    #[Test]
    public function it_passes_when_http3_enabled_with_sufficient_max_age(): void
    {
        $config = new DeployConfig(http3Enabled: true, http3AltSvcMaxAge: 86400);
        $check = new Http3ReadinessCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('HTTP/3 is enabled', $result->message);
        self::assertStringContainsString('86400', $result->message);
    }

    #[Test]
    public function it_passes_at_exactly_minimum_max_age(): void
    {
        $config = new DeployConfig(http3Enabled: true, http3AltSvcMaxAge: 3600);
        $check = new Http3ReadinessCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_warns_when_max_age_too_low_in_production(): void
    {
        $config = new DeployConfig(http3Enabled: true, http3AltSvcMaxAge: 60);
        $check = new Http3ReadinessCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('60 seconds', $result->message);
        self::assertStringContainsString('3600', $result->message);
    }

    #[Test]
    public function it_warns_when_max_age_too_low_in_staging(): void
    {
        $config = new DeployConfig(http3Enabled: true, http3AltSvcMaxAge: 100);
        $check = new Http3ReadinessCheck($config);

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('100 seconds', $result->message);
    }

    #[Test]
    public function it_passes_when_max_age_too_low_in_local(): void
    {
        $config = new DeployConfig(http3Enabled: true, http3AltSvcMaxAge: 10);
        $check = new Http3ReadinessCheck($config);

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('not enforced in local', $result->message);
    }

    #[Test]
    public function it_passes_when_http3_disabled_regardless_of_environment(): void
    {
        $config = new DeployConfig(http3Enabled: false, http3AltSvcMaxAge: 0);
        $check = new Http3ReadinessCheck($config);

        foreach (['local', 'staging', 'production'] as $env) {
            $result = $check->check($env);
            self::assertSame(CheckSeverity::Pass, $result->severity, "Expected pass for {$env}");
        }
    }

    #[Test]
    public function production_warning_recommendations_mention_config(): void
    {
        $config = new DeployConfig(http3Enabled: true, http3AltSvcMaxAge: 10);
        $check = new Http3ReadinessCheck($config);

        $result = $check->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('config/deploy.php', $joined);
        self::assertStringContainsString('3600', $joined);
    }

    #[Test]
    public function it_warns_with_zero_max_age_in_production(): void
    {
        $config = new DeployConfig(http3Enabled: true, http3AltSvcMaxAge: 0);
        $check = new Http3ReadinessCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
    }
}
