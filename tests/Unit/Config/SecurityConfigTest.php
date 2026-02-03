<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CsrfConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\RateLimitConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Config\SessionConfig;

#[CoversClass(SecurityConfig::class)]
#[CoversClass(SessionConfig::class)]
#[CoversClass(CsrfConfig::class)]
#[CoversClass(SecurityHeadersConfig::class)]
#[CoversClass(RateLimitConfig::class)]
final class SecurityConfigTest extends TestCase
{
    private Environment $environment;

    protected function setUp(): void
    {
        putenv('SESSION_COOKIE_NAME');
        $this->environment = Environment::load();
    }

    protected function tearDown(): void
    {
        putenv('SESSION_COOKIE_NAME');
    }

    #[Test]
    public function fromArrayBuildsAllSubConfigs(): void
    {
        $data = [
            'session' => [
                'cookie_name' => 'MY_SESSION',
                'lifetime' => 3600,
                'cookie_httponly' => true,
                'cookie_secure' => false,
                'cookie_samesite' => 'Lax',
                'regenerate_on_privilege_change' => false,
            ],
            'csrf' => [
                'enabled' => false,
                'token_length' => 64,
                'header_name' => 'X-Custom-CSRF',
                'form_field_name' => '_token',
            ],
            'headers' => [
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
            ],
            'rate_limiting' => [
                'enabled' => false,
                'default_limit' => 100,
                'default_window' => 120,
            ],
        ];

        $config = SecurityConfig::fromArray($data, $this->environment);

        // Session
        self::assertSame('MY_SESSION', $config->session->cookieName);
        self::assertSame(3600, $config->session->lifetime);
        self::assertTrue($config->session->cookieHttpOnly);
        self::assertFalse($config->session->cookieSecure);
        self::assertSame('Lax', $config->session->cookieSameSite);
        self::assertFalse($config->session->regenerateOnPrivilegeChange);

        // CSRF
        self::assertFalse($config->csrf->enabled);
        self::assertSame(64, $config->csrf->tokenLength);
        self::assertSame('X-Custom-CSRF', $config->csrf->headerName);
        self::assertSame('_token', $config->csrf->formFieldName);

        // Headers
        self::assertSame('nosniff', $config->headers->headers['X-Content-Type-Options']);
        self::assertSame('SAMEORIGIN', $config->headers->headers['X-Frame-Options']);

        // Rate limiting
        self::assertFalse($config->rateLimit->enabled);
        self::assertSame(100, $config->rateLimit->defaultLimit);
        self::assertSame(120, $config->rateLimit->defaultWindow);
    }

    #[Test]
    public function defaultsAppliedWhenKeysAreMissing(): void
    {
        $config = SecurityConfig::fromArray([], $this->environment);

        self::assertSame('PULSAR_SESSION', $config->session->cookieName);
        self::assertSame(7200, $config->session->lifetime);
        self::assertTrue($config->session->cookieHttpOnly);
        self::assertTrue($config->session->cookieSecure);
        self::assertSame('Strict', $config->session->cookieSameSite);
        self::assertTrue($config->session->regenerateOnPrivilegeChange);

        self::assertTrue($config->csrf->enabled);
        self::assertSame(32, $config->csrf->tokenLength);
        self::assertSame('X-CSRF-Token', $config->csrf->headerName);
        self::assertSame('_csrf_token', $config->csrf->formFieldName);

        self::assertSame([], $config->headers->headers);

        self::assertTrue($config->rateLimit->enabled);
        self::assertSame(60, $config->rateLimit->defaultLimit);
        self::assertSame(60, $config->rateLimit->defaultWindow);
    }

    #[Test]
    public function envOverridesSessionCookieName(): void
    {
        putenv('SESSION_COOKIE_NAME=ENV_SESSION');

        $environment = Environment::load();
        $config = SecurityConfig::fromArray([
            'session' => ['cookie_name' => 'FILE_SESSION'],
        ], $environment);

        self::assertSame('ENV_SESSION', $config->session->cookieName);
    }

    #[Test]
    public function securityHeadersConfigFromArray(): void
    {
        $config = SecurityHeadersConfig::fromArray([
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => "default-src 'self'",
        ]);

        self::assertCount(2, $config->headers);
        self::assertSame('max-age=31536000; includeSubDomains', $config->headers['Strict-Transport-Security']);
    }
}
