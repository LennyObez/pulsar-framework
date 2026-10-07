<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CrossOriginConfig;
use Pulsar\Config\CspConfig;
use Pulsar\Config\CsrfConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\HstsConfig;
use Pulsar\Config\RateLimitConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Config\SessionConfig;
use ReflectionClass;

#[CoversClass(SecurityConfig::class)]
#[CoversClass(SessionConfig::class)]
#[CoversClass(CsrfConfig::class)]
#[CoversClass(SecurityHeadersConfig::class)]
#[CoversClass(RateLimitConfig::class)]
#[CoversClass(CspConfig::class)]
#[CoversClass(HstsConfig::class)]
#[CoversClass(CrossOriginConfig::class)]
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
        putenv('APP_ENV');
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
        // cookie_secure defaults to secure only in production; pin it so the
        // secure-default assertion is deterministic.
        putenv('APP_ENV=production');
        $config = SecurityConfig::fromArray([], Environment::load());

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

    #[Test]
    public function securityHeadersConfigHasSubConfigProperties(): void
    {
        $config = new SecurityHeadersConfig(headers: []);

        self::assertInstanceOf(CspConfig::class, $config->csp);
        self::assertInstanceOf(HstsConfig::class, $config->hsts);
        self::assertInstanceOf(CrossOriginConfig::class, $config->crossOrigin);
    }

    #[Test]
    public function securityHeadersConfigFromArrayWithNestedSubConfigs(): void
    {
        $config = SecurityHeadersConfig::fromArray([
            'X-Content-Type-Options' => 'nosniff',
            'csp' => [
                'enabled' => true,
                'report_only' => true,
                'default_src' => "'none'",
            ],
            'hsts' => [
                'max_age' => 63072000,
                'preload' => true,
            ],
            'cross_origin' => [
                'opener_policy' => 'same-origin-allow-popups',
                'embedder_policy' => 'require-corp',
            ],
        ]);

        // Flat headers still parsed
        self::assertSame('nosniff', $config->headers['X-Content-Type-Options']);
        // Sub-config arrays are NOT included in flat headers
        self::assertArrayNotHasKey('csp', $config->headers);
        self::assertArrayNotHasKey('hsts', $config->headers);
        self::assertArrayNotHasKey('cross_origin', $config->headers);

        // CSP sub-config
        self::assertTrue($config->csp->enabled);
        self::assertTrue($config->csp->reportOnly);
        self::assertSame("'none'", $config->csp->defaultSrc);

        // HSTS sub-config
        self::assertSame(63072000, $config->hsts->maxAge);
        self::assertTrue($config->hsts->preload);

        // Cross-Origin sub-config
        self::assertSame('same-origin-allow-popups', $config->crossOrigin->openerPolicy);
        self::assertSame('require-corp', $config->crossOrigin->embedderPolicy);
    }

    #[Test]
    public function securityHeadersConfigFromArrayWithFlatOnlyData(): void
    {
        $config = SecurityHeadersConfig::fromArray([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);

        // Flat headers still work
        self::assertSame('nosniff', $config->headers['X-Content-Type-Options']);
        self::assertSame('DENY', $config->headers['X-Frame-Options']);

        // Sub-configs get defaults
        self::assertTrue($config->csp->enabled);
        self::assertSame("'self'", $config->csp->defaultSrc);
        self::assertTrue($config->hsts->enabled);
        self::assertSame(63072000, $config->hsts->maxAge);
        self::assertSame('same-origin', $config->crossOrigin->openerPolicy);
    }

    #[Test]
    public function effectiveHeadersIncludesCspWhenEnabled(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            csp: new CspConfig(enabled: true, defaultSrc: "'self'"),
        );

        $effective = $config->effectiveHeaders();

        self::assertArrayHasKey('Content-Security-Policy', $effective);
        self::assertStringContainsString("default-src 'self'", $effective['Content-Security-Policy']);
    }

    #[Test]
    public function effectiveHeadersExcludesCspWhenDisabled(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            csp: new CspConfig(enabled: false),
        );

        $effective = $config->effectiveHeaders();

        // Disabling CspConfig only suppresses the *custom* CSP; the restrictive
        // baseline Content-Security-Policy from MINIMUM_HEADERS remains as a
        // defense-in-depth floor (see SecurityHeadersConfig::MINIMUM_HEADERS).
        // It must never escalate to a report-only-only state.
        self::assertSame(
            "default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'",
            $effective['Content-Security-Policy'],
        );
        self::assertArrayNotHasKey('Content-Security-Policy-Report-Only', $effective);
    }

    #[Test]
    public function effectiveHeadersIncludesCrossOriginWhenSet(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            crossOrigin: new CrossOriginConfig(
                openerPolicy: 'same-origin',
                embedderPolicy: 'require-corp',
                resourcePolicy: 'same-origin',
            ),
        );

        $effective = $config->effectiveHeaders();

        self::assertSame('same-origin', $effective['Cross-Origin-Opener-Policy']);
        self::assertSame('require-corp', $effective['Cross-Origin-Embedder-Policy']);
        self::assertSame('same-origin', $effective['Cross-Origin-Resource-Policy']);
    }

    #[Test]
    public function effectiveHeadersExcludesEmptyCrossOriginValues(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            crossOrigin: new CrossOriginConfig(
                openerPolicy: '',
                embedderPolicy: '',
                resourcePolicy: '',
            ),
        );

        $effective = $config->effectiveHeaders();

        // Empty CrossOriginConfig values mean "do not override"; the secure
        // baseline Cross-Origin-* headers from MINIMUM_HEADERS remain so an
        // empty override never weakens cross-origin isolation.
        self::assertSame('same-origin', $effective['Cross-Origin-Opener-Policy']);
        self::assertSame('require-corp', $effective['Cross-Origin-Embedder-Policy']);
        self::assertSame('same-origin', $effective['Cross-Origin-Resource-Policy']);
    }

    #[Test]
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(SecurityConfig::class);
        self::assertTrue($reflection->isFinal(), 'SecurityConfig must be final to prevent config DTO subclassing');
    }
}
