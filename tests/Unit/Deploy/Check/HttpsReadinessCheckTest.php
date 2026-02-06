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
use Pulsar\Deploy\Check\HttpsReadinessCheck;
use Pulsar\Deploy\CheckSeverity;

#[CoversClass(HttpsReadinessCheck::class)]
final class HttpsReadinessCheckTest extends TestCase
{
    #[Test]
    public function it_returns_name(): void
    {
        $check = new HttpsReadinessCheck($this->buildSecurityConfig([]));

        self::assertSame('https-readiness', $check->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        $check = new HttpsReadinessCheck($this->buildSecurityConfig([]));

        self::assertSame('Validates HSTS (Strict-Transport-Security) header is configured', $check->getDescription());
    }

    #[Test]
    public function it_passes_when_hsts_header_present(): void
    {
        $check = new HttpsReadinessCheck($this->buildSecurityConfig([
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ]));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('HSTS header is configured', $result->message);
    }

    #[Test]
    public function it_passes_with_case_insensitive_hsts_header(): void
    {
        $check = new HttpsReadinessCheck($this->buildSecurityConfig([
            'strict-transport-security' => 'max-age=31536000',
        ]));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_errors_when_hsts_missing_in_production(): void
    {
        $check = new HttpsReadinessCheck($this->buildSecurityConfig([]));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('Strict-Transport-Security', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_warns_when_hsts_missing_in_staging(): void
    {
        $check = new HttpsReadinessCheck($this->buildSecurityConfig([]));

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('Strict-Transport-Security', $result->message);
    }

    #[Test]
    public function it_passes_when_hsts_missing_in_local(): void
    {
        $check = new HttpsReadinessCheck($this->buildSecurityConfig([]));

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('not required in local', $result->message);
    }

    #[Test]
    public function production_error_recommendations_mention_hsts(): void
    {
        $check = new HttpsReadinessCheck($this->buildSecurityConfig([]));

        $result = $check->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('Strict-Transport-Security', $joined);
        self::assertStringContainsString('max-age', $joined);
    }

    #[Test]
    public function it_passes_when_hsts_present_among_other_headers(): void
    {
        $check = new HttpsReadinessCheck($this->buildSecurityConfig([
            'X-Content-Type-Options' => 'nosniff',
            'Strict-Transport-Security' => 'max-age=86400',
            'X-Frame-Options' => 'DENY',
        ]));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    /**
     * @param array<string, string> $headers
     */
    private function buildSecurityConfig(array $headers): SecurityConfig
    {
        return new SecurityConfig(
            session: $this->createStub(SessionConfig::class),
            csrf: $this->createStub(CsrfConfig::class),
            headers: new SecurityHeadersConfig(headers: $headers),
            rateLimit: new RateLimitConfig(enabled: true, defaultLimit: 60, defaultWindow: 60),
        );
    }
}
