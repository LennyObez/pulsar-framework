<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CsrfConfig;
use Pulsar\Config\RateLimitConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Deploy\Check\RateLimitCheck;
use Pulsar\Deploy\CheckSeverity;

#[CoversClass(RateLimitCheck::class)]
final class RateLimitCheckTest extends TestCase
{
    #[Test]
    public function it_returns_name(): void
    {
        $check = new RateLimitCheck($this->buildConfig(enabled: true));

        self::assertSame('rate-limiting', $check->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        $check = new RateLimitCheck($this->buildConfig(enabled: true));

        self::assertSame('Validates rate limiting is enabled for the target environment', $check->getDescription());
    }

    #[Test]
    public function it_passes_when_rate_limiting_is_enabled(): void
    {
        $check = new RateLimitCheck($this->buildConfig(enabled: true, limit: 100, window: 60));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('Rate limiting is enabled', $result->message);
        self::assertStringContainsString('100', $result->message);
        self::assertStringContainsString('60', $result->message);
    }

    #[Test]
    public function it_passes_when_rate_limiting_enabled_in_staging(): void
    {
        $check = new RateLimitCheck($this->buildConfig(enabled: true));

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_warns_when_rate_limiting_disabled_in_production(): void
    {
        $check = new RateLimitCheck($this->buildConfig(enabled: false));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('Rate limiting is not enabled', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_warns_when_rate_limiting_disabled_in_staging(): void
    {
        $check = new RateLimitCheck($this->buildConfig(enabled: false));

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('Rate limiting is not enabled', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_passes_when_rate_limiting_disabled_in_local(): void
    {
        $check = new RateLimitCheck($this->buildConfig(enabled: false));

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('not required in local', $result->message);
    }

    #[Test]
    public function production_recommendations_mention_config_and_routes(): void
    {
        $check = new RateLimitCheck($this->buildConfig(enabled: false));

        $result = $check->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('config/security.php', $joined);
        self::assertStringContainsString('authentication endpoints', $joined);
    }

    private function buildConfig(bool $enabled, int $limit = 60, int $window = 60): SecurityConfig
    {
        return new SecurityConfig(
            session: new SessionConfig(
                cookieName: 'PULSAR_SESSION',
                lifetime: 7200,
                cookieHttpOnly: true,
                cookieSecure: true,
                cookieSameSite: 'Strict',
                regenerateOnPrivilegeChange: true,
            ),
            csrf: new CsrfConfig(
                enabled: true,
                tokenLength: 32,
                headerName: 'X-CSRF-Token',
                formFieldName: '_csrf_token',
            ),
            headers: new SecurityHeadersConfig(headers: []),
            rateLimit: new RateLimitConfig(
                enabled: $enabled,
                defaultLimit: $limit,
                defaultWindow: $window,
            ),
        );
    }
}
