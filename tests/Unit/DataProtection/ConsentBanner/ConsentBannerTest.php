<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\ConsentBanner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\ConsentBanner\ConsentBannerConfig;
use Pulsar\DataProtection\ConsentBanner\ConsentBannerRenderer;
use Pulsar\DataProtection\ConsentBanner\ConsentCategory;

#[CoversClass(ConsentBannerConfig::class)]
#[CoversClass(ConsentBannerRenderer::class)]
#[CoversClass(ConsentCategory::class)]
final class ConsentBannerTest extends TestCase
{
    #[Test]
    public function renderReturnsEmptyWhenDisabled(): void
    {
        $config = new ConsentBannerConfig(enabled: false);
        $renderer = new ConsentBannerRenderer($config);

        self::assertSame('', $renderer->render());
    }

    #[Test]
    public function renderContainsBannerMarkup(): void
    {
        $config = new ConsentBannerConfig();
        $renderer = new ConsentBannerRenderer($config);

        $html = $renderer->render();

        self::assertStringContainsString('pulsar-consent-banner', $html);
        self::assertStringContainsString('Accept All', $html);
        self::assertStringContainsString('Reject Non-Essential', $html);
    }

    #[Test]
    public function renderIncludesPrivacyPolicyLink(): void
    {
        $config = new ConsentBannerConfig(privacyPolicyUrl: '/legal/privacy');
        $renderer = new ConsentBannerRenderer($config);

        $html = $renderer->render();

        self::assertStringContainsString('/legal/privacy', $html);
    }

    #[Test]
    public function renderIncludesCategoryCheckboxes(): void
    {
        $config = new ConsentBannerConfig(
            categories: [
                new ConsentCategory('necessary', 'Necessary', 'Required cookies', true, true),
                new ConsentCategory('analytics', 'Analytics', 'Tracking cookies', false, false),
            ],
            granularOptIn: true,
        );
        $renderer = new ConsentBannerRenderer($config);

        $html = $renderer->render();

        self::assertStringContainsString('consent-necessary', $html);
        self::assertStringContainsString('consent-analytics', $html);
        self::assertStringContainsString('disabled', $html); // Necessary is required
    }

    #[Test]
    public function renderOmitsCategoryCheckboxesWhenNotGranular(): void
    {
        $config = new ConsentBannerConfig(granularOptIn: false);
        $renderer = new ConsentBannerRenderer($config);

        $html = $renderer->render();

        self::assertStringNotContainsString('pulsar-consent-categories', $html);
    }

    #[Test]
    public function renderIncludesCookieConfiguration(): void
    {
        $config = new ConsentBannerConfig(
            cookieName: 'my_consent',
            cookieTtlDays: 180,
        );
        $renderer = new ConsentBannerRenderer($config);

        $html = $renderer->render();

        self::assertStringContainsString('my_consent', $html);
    }

    #[Test]
    public function configFromArrayUsesDefaults(): void
    {
        $config = ConsentBannerConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('bottom', $config->position);
        self::assertTrue($config->granularOptIn);
        self::assertSame('pulsar_consent', $config->cookieName);
        self::assertSame(365, $config->cookieTtlDays);
        self::assertNotEmpty($config->categories); // Default categories
    }

    #[Test]
    public function configFromArrayOverridesValues(): void
    {
        $config = ConsentBannerConfig::fromArray([
            'enabled' => false,
            'position' => 'top',
            'privacy_policy_url' => '/privacy-custom',
            'granular_opt_in' => false,
            'cookie_name' => 'gdpr_ok',
            'cookie_ttl_days' => 90,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('top', $config->position);
        self::assertSame('/privacy-custom', $config->privacyPolicyUrl);
        self::assertFalse($config->granularOptIn);
        self::assertSame('gdpr_ok', $config->cookieName);
        self::assertSame(90, $config->cookieTtlDays);
    }

    #[Test]
    public function categoryFromArrayParsesCorrectly(): void
    {
        $category = ConsentCategory::fromArray('analytics', [
            'label' => 'Analytics Cookies',
            'description' => 'Used for tracking',
            'required' => false,
            'default_enabled' => true,
        ]);

        self::assertSame('analytics', $category->key);
        self::assertSame('Analytics Cookies', $category->label);
        self::assertSame('Used for tracking', $category->description);
        self::assertFalse($category->required);
        self::assertTrue($category->defaultEnabled);
    }

    #[Test]
    public function categoryFromArrayUsesKeyAsLabelFallback(): void
    {
        $category = ConsentCategory::fromArray('marketing', []);

        self::assertSame('marketing', $category->key);
        self::assertSame('marketing', $category->label);
    }

    #[Test]
    public function renderEscapesHtmlInCategoryLabels(): void
    {
        $config = new ConsentBannerConfig(
            categories: [
                new ConsentCategory('xss', '<script>alert(1)</script>', 'desc', false, false),
            ],
            granularOptIn: true,
        );
        $renderer = new ConsentBannerRenderer($config);

        $html = $renderer->render();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderHasBannerPositionClass(): void
    {
        $config = new ConsentBannerConfig(position: 'bottom-right');
        $renderer = new ConsentBannerRenderer($config);

        $html = $renderer->render();

        self::assertStringContainsString('pulsar-consent-bottom-right', $html);
    }

    #[Test]
    public function renderHasAriaLabelForAccessibility(): void
    {
        $config = new ConsentBannerConfig();
        $renderer = new ConsentBannerRenderer($config);

        $html = $renderer->render();

        self::assertStringContainsString('role="dialog"', $html);
        self::assertStringContainsString('aria-label="Cookie consent"', $html);
    }
}
