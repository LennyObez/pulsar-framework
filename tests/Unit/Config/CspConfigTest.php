<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CspConfig;
use Pulsar\Config\Exception\ConfigException;

#[CoversClass(CspConfig::class)]
final class CspConfigTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesConfigWithAllDirectives(): void
    {
        $config = CspConfig::fromArray([
            'enabled' => true,
            'report_only' => true,
            'default_src' => "'self' https:",
            'script_src' => "'self' 'unsafe-inline'",
            'style_src' => "'self' 'unsafe-inline'",
            'img_src' => "'self' data:",
            'font_src' => "'self' https://fonts.gstatic.com",
            'connect_src' => "'self' https://api.example.com",
            'media_src' => "'none'",
            'object_src' => "'none'",
            'frame_src' => "'none'",
            'frame_ancestors' => "'none'",
            'base_uri' => "'self'",
            'form_action' => "'self'",
            'upgrade_insecure_requests' => true,
            'report_uri' => '/csp-report',
            'report_to' => 'csp-endpoint',
            'custom_directives' => ['worker-src' => "'self'"],
        ]);

        self::assertTrue($config->enabled);
        self::assertTrue($config->reportOnly);
        self::assertSame("'self' https:", $config->defaultSrc);
        self::assertSame("'self' 'unsafe-inline'", $config->scriptSrc);
        self::assertSame("'self' 'unsafe-inline'", $config->styleSrc);
        self::assertSame("'self' data:", $config->imgSrc);
        self::assertSame("'self' https://fonts.gstatic.com", $config->fontSrc);
        self::assertSame("'self' https://api.example.com", $config->connectSrc);
        self::assertSame("'none'", $config->mediaSrc);
        self::assertSame("'none'", $config->objectSrc);
        self::assertSame("'none'", $config->frameSrc);
        self::assertSame("'none'", $config->frameAncestors);
        self::assertSame("'self'", $config->baseUri);
        self::assertSame("'self'", $config->formAction);
        self::assertTrue($config->upgradeInsecureRequests);
        self::assertSame('/csp-report', $config->reportUri);
        self::assertSame('csp-endpoint', $config->reportTo);
        self::assertSame(['worker-src' => "'self'"], $config->customDirectives);
    }

    #[Test]
    public function toHeaderValueProducesValidCspString(): void
    {
        $config = new CspConfig(
            defaultSrc: "'self'",
            scriptSrc: "'self' 'unsafe-inline'",
            styleSrc: "'self'",
            imgSrc: "'self' data:",
            objectSrc: "'none'",
            baseUri: "'self'",
            formAction: "'self'",
        );

        $value = $config->toHeaderValue();

        self::assertStringContainsString("default-src 'self'", $value);
        self::assertStringContainsString("script-src 'self' 'unsafe-inline'", $value);
        self::assertStringContainsString("style-src 'self'", $value);
        self::assertStringContainsString("img-src 'self' data:", $value);
        self::assertStringContainsString("object-src 'none'", $value);
        self::assertStringContainsString("base-uri 'self'", $value);
        self::assertStringContainsString("form-action 'self'", $value);
    }

    #[Test]
    public function headerNameReturnsContentSecurityPolicyByDefault(): void
    {
        $config = new CspConfig();

        self::assertSame('Content-Security-Policy', $config->headerName());
    }

    #[Test]
    public function headerNameReturnsReportOnlyWhenEnabled(): void
    {
        $config = new CspConfig(reportOnly: true);

        self::assertSame('Content-Security-Policy-Report-Only', $config->headerName());
    }

    #[Test]
    public function defaultsAreRestrictive(): void
    {
        $config = new CspConfig();

        self::assertTrue($config->enabled);
        self::assertFalse($config->reportOnly);
        self::assertSame("'self'", $config->defaultSrc);
        self::assertSame("'self'", $config->scriptSrc);
        self::assertSame("'self'", $config->styleSrc);
        self::assertSame("'self'", $config->imgSrc);
        self::assertSame("'self'", $config->fontSrc);
        self::assertSame("'self'", $config->connectSrc);
        self::assertSame("'self'", $config->mediaSrc);
        self::assertSame("'none'", $config->objectSrc);
        self::assertSame("'self'", $config->frameSrc);
        self::assertSame("'self'", $config->frameAncestors);
        self::assertSame("'self'", $config->baseUri);
        self::assertSame("'self'", $config->formAction);
        self::assertFalse($config->upgradeInsecureRequests);
        self::assertSame('', $config->reportUri);
        self::assertSame('', $config->reportTo);
        self::assertSame([], $config->customDirectives);
    }

    #[Test]
    public function customDirectivesAreAppended(): void
    {
        $config = new CspConfig(
            customDirectives: ['worker-src' => "'self'", 'manifest-src' => "'self'"],
        );

        $value = $config->toHeaderValue();

        self::assertStringContainsString("worker-src 'self'", $value);
        self::assertStringContainsString("manifest-src 'self'", $value);
    }

    #[Test]
    public function upgradeInsecureRequestsAddsDirectiveWhenTrue(): void
    {
        $config = new CspConfig(upgradeInsecureRequests: true);

        $value = $config->toHeaderValue();

        self::assertStringContainsString('upgrade-insecure-requests', $value);
    }

    #[Test]
    public function upgradeInsecureRequestsOmittedWhenFalse(): void
    {
        $config = new CspConfig(upgradeInsecureRequests: false);

        $value = $config->toHeaderValue();

        self::assertStringNotContainsString('upgrade-insecure-requests', $value);
    }

    #[Test]
    public function disabledCspReturnsEmptyHeaderValue(): void
    {
        $config = new CspConfig(enabled: false);

        self::assertSame('', $config->toHeaderValue());
    }

    #[Test]
    public function reportUriAndReportToIncludedWhenSet(): void
    {
        $config = new CspConfig(
            reportUri: '/csp-report',
            reportTo: 'csp-endpoint',
        );

        $value = $config->toHeaderValue();

        self::assertStringContainsString('report-uri /csp-report', $value);
        self::assertStringContainsString('report-to csp-endpoint', $value);
    }

    #[Test]
    public function reportUriOmittedWhenEmpty(): void
    {
        $config = new CspConfig();

        $value = $config->toHeaderValue();

        self::assertStringNotContainsString('report-uri', $value);
        self::assertStringNotContainsString('report-to', $value);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = CspConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertFalse($config->reportOnly);
        self::assertSame("'self'", $config->defaultSrc);
    }

    #[Test]
    public function fromArrayRejectsReportUriWithDirectiveSeparator(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('report_uri');

        (void) CspConfig::fromArray([
            'report_uri' => 'https://a.example; default-src *',
        ]);
    }

    #[Test]
    public function fromArrayRejectsCustomDirectiveValueWithSeparator(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('custom_directives');

        (void) CspConfig::fromArray([
            'custom_directives' => ['worker-src' => "'self'; default-src *"],
        ]);
    }

    #[Test]
    public function fromArrayRejectsCustomDirectiveNameWithSeparator(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('custom_directives');

        (void) CspConfig::fromArray([
            'custom_directives' => ["worker-src 'self'; default-src" => '*'],
        ]);
    }

    #[Test]
    public function fromArrayAcceptsRelativeReportUri(): void
    {
        $config = CspConfig::fromArray([
            'report_uri' => '/csp-report',
        ]);

        self::assertSame('/csp-report', $config->reportUri);
    }
}
