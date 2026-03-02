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
        // Defense-in-depth (F30.4 / F1.3): even with default config, CSP and
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
        // F30.4: disabling CspConfig must not silently drop the CSP header.
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
        // F30.4: empty CrossOriginConfig values must not silently drop the
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
