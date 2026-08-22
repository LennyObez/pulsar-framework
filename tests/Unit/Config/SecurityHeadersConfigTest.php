<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CrossOriginConfig;
use Pulsar\Config\CspConfig;
use Pulsar\Config\HstsConfig;
use Pulsar\Config\SecurityHeadersConfig;

#[CoversClass(SecurityHeadersConfig::class)]
final class SecurityHeadersConfigTest extends TestCase
{
    #[Test]
    public function effectiveHeadersIncludesMinimumDefaults(): void
    {
        $config = new SecurityHeadersConfig(headers: []);

        $headers = $config->effectiveHeaders();

        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        self::assertSame('0', $headers['X-XSS-Protection']);
        self::assertStringContainsString('camera=()', $headers['Permissions-Policy']);
        self::assertStringContainsString('microphone=()', $headers['Permissions-Policy']);
        self::assertStringContainsString('geolocation=()', $headers['Permissions-Policy']);
    }

    #[Test]
    public function effectiveHeadersAlwaysIncludesBaselineCspAndCrossOrigin(): void
    {
        // Defense-in-depth: even with default config, CSP and
        // Cross-Origin isolation headers must be present so a misconfigured
        // CspConfig / CrossOriginConfig cannot silently strip these protections.
        $config = new SecurityHeadersConfig(headers: []);

        $headers = $config->effectiveHeaders();

        self::assertArrayHasKey('Content-Security-Policy', $headers);
        self::assertStringContainsString("default-src 'self'", $headers['Content-Security-Policy']);
        self::assertStringContainsString("object-src 'none'", $headers['Content-Security-Policy']);
        self::assertStringContainsString("frame-ancestors 'self'", $headers['Content-Security-Policy']);
        self::assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
        self::assertSame('require-corp', $headers['Cross-Origin-Embedder-Policy']);
        self::assertSame('same-origin', $headers['Cross-Origin-Resource-Policy']);
    }

    #[Test]
    public function userHeadersOverrideDefaults(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Custom' => 'custom-value',
        ]);

        $headers = $config->effectiveHeaders();

        self::assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        self::assertSame('custom-value', $headers['X-Custom']);
        // Other defaults still present
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    #[Test]
    public function effectiveHeadersIncludesCspWhenEnabled(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            csp: new CspConfig(enabled: true, defaultSrc: "'self'"),
        );

        $headers = $config->effectiveHeaders();

        self::assertArrayHasKey('Content-Security-Policy', $headers);
        self::assertStringContainsString("default-src 'self'", $headers['Content-Security-Policy']);
    }

    #[Test]
    public function effectiveHeadersKeepsBaselineCspWhenCspConfigDisabled(): void
    {
        // Disabling CspConfig must not silently drop the CSP header.
        // The MINIMUM_HEADERS baseline keeps a restrictive default in place.
        $config = new SecurityHeadersConfig(
            headers: [],
            csp: new CspConfig(enabled: false),
        );

        $headers = $config->effectiveHeaders();

        self::assertArrayHasKey('Content-Security-Policy', $headers);
        self::assertStringContainsString("default-src 'self'", $headers['Content-Security-Policy']);
        self::assertArrayNotHasKey('Content-Security-Policy-Report-Only', $headers);
    }

    #[Test]
    public function effectiveHeadersUsesCspReportOnlyHeader(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            csp: new CspConfig(enabled: true, reportOnly: true),
        );

        $headers = $config->effectiveHeaders();

        self::assertArrayHasKey('Content-Security-Policy-Report-Only', $headers);
        self::assertArrayNotHasKey('Content-Security-Policy', $headers);
    }

    #[Test]
    public function effectiveHeadersIncludesCrossOriginPolicies(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            crossOrigin: new CrossOriginConfig(
                openerPolicy: 'same-origin',
                embedderPolicy: 'require-corp',
                resourcePolicy: 'same-site',
            ),
        );

        $headers = $config->effectiveHeaders();

        self::assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
        self::assertSame('require-corp', $headers['Cross-Origin-Embedder-Policy']);
        self::assertSame('same-site', $headers['Cross-Origin-Resource-Policy']);
    }

    #[Test]
    public function effectiveHeadersKeepsBaselineCrossOriginWhenConfigEmpty(): void
    {
        // Empty CrossOriginConfig values must not silently drop the
        // Cross-Origin isolation headers; baseline values from MINIMUM_HEADERS
        // remain in place.
        $config = new SecurityHeadersConfig(
            headers: [],
            crossOrigin: new CrossOriginConfig(
                openerPolicy: '',
                embedderPolicy: '',
                resourcePolicy: '',
            ),
        );

        $headers = $config->effectiveHeaders();

        self::assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
        self::assertSame('require-corp', $headers['Cross-Origin-Embedder-Policy']);
        self::assertSame('same-origin', $headers['Cross-Origin-Resource-Policy']);
    }

    #[Test]
    public function effectiveHeadersDoesNotIncludeHsts(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 31536000),
        );

        $headers = $config->effectiveHeaders();

        // HSTS is handled by middleware, not in effectiveHeaders()
        self::assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }

    #[Test]
    public function effectiveHeadersDropsLiteralHstsForConditionalEmission(): void
    {
        // A literal Strict-Transport-Security must NOT appear in the unconditional
        // header set; the middleware emits it conditionally (secure requests only,
        // RFC 6797 §7.2) via effectiveHstsHeader().
        $config = new SecurityHeadersConfig(
            headers: ['Strict-Transport-Security' => 'max-age=63072000; preload'],
        );

        self::assertArrayNotHasKey('Strict-Transport-Security', $config->effectiveHeaders());
    }

    #[Test]
    public function effectiveHstsHeaderHonorsLiteralOverStructured(): void
    {
        $config = new SecurityHeadersConfig(
            headers: ['Strict-Transport-Security' => 'max-age=63072000; includeSubDomains; preload'],
            hsts: new HstsConfig(enabled: true, maxAge: 63072000, includeSubDomains: true, preload: false),
        );

        self::assertSame('max-age=63072000; includeSubDomains; preload', $config->effectiveHstsHeader());
    }

    #[Test]
    public function effectiveHstsHeaderFallsBackToStructuredWhenNoLiteral(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 31536000, includeSubDomains: false),
        );

        self::assertSame('max-age=31536000', $config->effectiveHstsHeader());
    }

    #[Test]
    public function effectiveHstsHeaderReturnsNullWhenDisabledAndNoLiteral(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: false),
        );

        self::assertNull($config->effectiveHstsHeader());
    }

    #[Test]
    public function literalHeaderLookupIsCaseInsensitive(): void
    {
        $config = new SecurityHeadersConfig(
            headers: ['strict-transport-security' => 'max-age=600'],
        );

        self::assertSame('max-age=600', $config->literalHeader('Strict-Transport-Security'));
        self::assertNull($config->literalHeader('X-Absent'));
        // A lowercase literal is still honored by effectiveHstsHeader().
        self::assertSame('max-age=600', $config->effectiveHstsHeader());
    }

    #[Test]
    public function literalPermissionsPolicyOverridesStructuredInEffectiveHeaders(): void
    {
        $config = new SecurityHeadersConfig(
            headers: ['Permissions-Policy' => 'geolocation=(self)'],
        );

        self::assertSame('geolocation=(self)', $config->effectiveHeaders()['Permissions-Policy']);
    }

    #[Test]
    public function shadowedStructuredHeadersReportsHstsConflict(): void
    {
        $config = new SecurityHeadersConfig(
            headers: ['Strict-Transport-Security' => 'max-age=63072000; includeSubDomains; preload'],
            hsts: new HstsConfig(enabled: true, maxAge: 63072000, includeSubDomains: true, preload: false),
        );

        $conflicts = $config->shadowedStructuredHeaders();

        self::assertCount(1, $conflicts);
        self::assertStringContainsString('Strict-Transport-Security', $conflicts[0]);
        self::assertStringContainsString('preload', $conflicts[0]);
    }

    #[Test]
    public function shadowedStructuredHeadersEmptyWhenLiteralMatchesStructured(): void
    {
        $config = new SecurityHeadersConfig(
            headers: ['Strict-Transport-Security' => 'max-age=63072000; includeSubDomains'],
            hsts: new HstsConfig(enabled: true, maxAge: 63072000, includeSubDomains: true, preload: false),
        );

        self::assertSame([], $config->shadowedStructuredHeaders());
    }

    #[Test]
    public function shadowedStructuredHeadersEmptyWithNoLiterals(): void
    {
        $config = new SecurityHeadersConfig(headers: []);

        self::assertSame([], $config->shadowedStructuredHeaders());
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = SecurityHeadersConfig::fromArray([
            'X-Custom-Header' => 'value',
            'csp' => ['enabled' => true, 'default_src' => "'none'"],
            'hsts' => ['max_age' => 86400, 'preload' => true],
            'cross_origin' => ['opener_policy' => 'unsafe-none'],
        ]);

        self::assertSame('value', $config->headers['X-Custom-Header']);
        self::assertTrue($config->csp->enabled);
        self::assertSame("'none'", $config->csp->defaultSrc);
        self::assertSame(86400, $config->hsts->maxAge);
        self::assertTrue($config->hsts->preload);
        self::assertSame('unsafe-none', $config->crossOrigin->openerPolicy);
    }

    #[Test]
    public function fromArrayWithEmptyConfig(): void
    {
        $config = SecurityHeadersConfig::fromArray([]);

        self::assertSame([], $config->headers);
        self::assertTrue($config->csp->enabled);
        self::assertTrue($config->hsts->enabled);
        self::assertSame('same-origin', $config->crossOrigin->openerPolicy);
    }

    #[Test]
    public function fromArrayRemovesSubConfigKeysFromHeaders(): void
    {
        $config = SecurityHeadersConfig::fromArray([
            'csp' => ['enabled' => false],
            'hsts' => ['enabled' => false],
            'cross_origin' => ['opener_policy' => ''],
            'X-Remaining' => 'kept',
        ]);

        // Sub-config keys should not appear in headers
        self::assertArrayNotHasKey('csp', $config->headers);
        self::assertArrayNotHasKey('hsts', $config->headers);
        self::assertArrayNotHasKey('cross_origin', $config->headers);
        self::assertSame('kept', $config->headers['X-Remaining']);
    }

    #[Test]
    public function fromArrayCoercesNonStringHeaderValues(): void
    {
        $config = SecurityHeadersConfig::fromArray([
            'X-Numeric' => 42,
            'X-Bool' => true,
        ]);

        self::assertSame('42', $config->headers['X-Numeric']);
        self::assertSame('1', $config->headers['X-Bool']);
    }

    #[Test]
    public function fromArrayHandlesNonArraySubConfigs(): void
    {
        $config = SecurityHeadersConfig::fromArray([
            'csp' => 'invalid',
            'hsts' => null,
            'cross_origin' => 123,
        ]);

        // Should use defaults when sub-configs are not arrays
        self::assertTrue($config->csp->enabled);
        self::assertTrue($config->hsts->enabled);
        self::assertSame('same-origin', $config->crossOrigin->openerPolicy);
    }
}
