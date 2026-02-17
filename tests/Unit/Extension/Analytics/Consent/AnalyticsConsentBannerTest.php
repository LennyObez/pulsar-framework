<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Consent\AnalyticsConsentBanner;

use function strlen;

#[CoversClass(AnalyticsConsentBanner::class)]
final class AnalyticsConsentBannerTest extends TestCase
{
    #[Test]
    public function renderProducesValidHtmlStructure(): void
    {
        $banner = new AnalyticsConsentBanner();

        $html = $banner->render();

        self::assertStringContainsString('id="plsr-consent-banner"', $html);
        self::assertStringContainsString('role="dialog"', $html);
        self::assertStringContainsString('aria-label="Analytics consent"', $html);
    }

    #[Test]
    public function renderIncludesAcceptAndDeclineButtons(): void
    {
        $banner = new AnalyticsConsentBanner();

        $html = $banner->render();

        self::assertStringContainsString('id="plsr-consent-accept"', $html);
        self::assertStringContainsString('id="plsr-consent-decline"', $html);
        self::assertStringContainsString('Accept analytics', $html);
        self::assertStringContainsString('Decline', $html);
    }

    #[Test]
    public function renderIncludesDefaultPrivacyPolicyLink(): void
    {
        $banner = new AnalyticsConsentBanner();

        $html = $banner->render();

        self::assertStringContainsString('href="/privacy"', $html);
        self::assertStringContainsString('Privacy policy', $html);
    }

    #[Test]
    public function renderUsesCustomPrivacyPolicyUrl(): void
    {
        $banner = new AnalyticsConsentBanner(privacyPolicyUrl: '/legal/data-privacy');

        $html = $banner->render();

        self::assertStringContainsString('href="/legal/data-privacy"', $html);
        self::assertStringNotContainsString('href="/privacy"', $html);
    }

    #[Test]
    public function renderEscapesPrivacyPolicyUrlForHtmlSafety(): void
    {
        $banner = new AnalyticsConsentBanner(privacyPolicyUrl: '/privacy?lang=en&version=2');

        $html = $banner->render();

        self::assertStringContainsString('href="/privacy?lang=en&amp;version=2"', $html);
        self::assertStringNotContainsString('href="/privacy?lang=en&version=2"', $html);
    }

    #[Test]
    public function renderIncludesLocalStorageJavaScript(): void
    {
        $banner = new AnalyticsConsentBanner();

        $html = $banner->render();

        self::assertStringContainsString('localStorage', $html);
        self::assertStringContainsString('plsr_analytics_consent', $html);
    }

    #[Test]
    public function renderIncludesConsentEndpoints(): void
    {
        $banner = new AnalyticsConsentBanner();

        $html = $banner->render();

        self::assertStringContainsString('/plsr/consent/grant', $html);
        self::assertStringContainsString('/plsr/consent/revoke', $html);
    }

    #[Test]
    public function renderUsesPuiCssClasses(): void
    {
        $banner = new AnalyticsConsentBanner();

        $html = $banner->render();

        self::assertStringContainsString('pui-consent-banner', $html);
        self::assertStringContainsString('pui-btn', $html);
        self::assertStringContainsString('pui-btn--primary', $html);
        self::assertStringContainsString('pui-btn--secondary', $html);
    }

    #[Test]
    public function renderStartsHiddenForProgressiveEnhancement(): void
    {
        $banner = new AnalyticsConsentBanner();

        $html = $banner->render();

        self::assertStringContainsString('style="display:none;"', $html);
    }

    #[Test]
    public function renderIncludesNoCookiePrivacyMessage(): void
    {
        $banner = new AnalyticsConsentBanner();

        $html = $banner->render();

        self::assertStringContainsString('No cookies are used', $html);
        self::assertStringContainsString('never shared with third parties', $html);
    }

    #[Test]
    #[DataProvider('privacyPolicyUrlProvider')]
    public function renderHandlesVariousPrivacyPolicyUrls(string $url, string $expectedHref): void
    {
        $banner = new AnalyticsConsentBanner(privacyPolicyUrl: $url);

        $html = $banner->render();

        self::assertStringContainsString('href="' . $expectedHref . '"', $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function privacyPolicyUrlProvider(): iterable
    {
        yield 'simple path' => ['/privacy', '/privacy'];
        yield 'nested path' => ['/legal/privacy-policy', '/legal/privacy-policy'];
        yield 'full url' => ['https://example.com/privacy', 'https://example.com/privacy'];
        yield 'path with special chars' => ['/privacy?v=1&l=en', '/privacy?v=1&amp;l=en'];
    }

    #[Test]
    public function renderIncludesCsrfTokenHandling(): void
    {
        $banner = new AnalyticsConsentBanner();

        $html = $banner->render();

        self::assertStringContainsString('csrf-token', $html);
        self::assertStringContainsString('X-CSRF-Token', $html);
    }

    #[Test]
    public function renderOutputIsNonEmpty(): void
    {
        $banner = new AnalyticsConsentBanner();

        $html = $banner->render();

        self::assertNotEmpty($html);
        self::assertGreaterThan(100, strlen($html));
    }
}
