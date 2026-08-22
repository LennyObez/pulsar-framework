<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\ConsentBanner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\ConsentBanner\ConsentBannerConfig;
use Pulsar\DataProtection\ConsentBanner\ConsentBannerRenderer;
use Pulsar\DataProtection\ConsentBanner\ConsentCategory;

use const ENT_QUOTES;

#[CoversClass(ConsentBannerRenderer::class)]
#[CoversClass(ConsentBannerConfig::class)]
#[CoversClass(ConsentCategory::class)]
final class ConsentBannerRendererTest extends TestCase
{
    #[Test]
    public function renderReturnsEmptyStringWhenDisabled(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(enabled: false);
        $renderer = new ConsentBannerRenderer($config);

        // Act & Assert
        self::assertSame('', $renderer->render());
    }

    #[Test]
    public function renderContainsBannerDivWithRoleDialog(): void
    {
        // Arrange
        $renderer = new ConsentBannerRenderer(new ConsentBannerConfig());

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringContainsString('role="dialog"', $html);
        self::assertStringContainsString('aria-label="Cookie consent"', $html);
    }

    #[Test]
    public function renderContainsAcceptAndRejectButtons(): void
    {
        // Arrange
        $renderer = new ConsentBannerRenderer(new ConsentBannerConfig());

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringContainsString('pulsar-consent-accept', $html);
        self::assertStringContainsString('Accept All', $html);
        self::assertStringContainsString('pulsar-consent-reject', $html);
        self::assertStringContainsString('Reject Non-Essential', $html);
    }

    #[Test]
    public function renderIncludesPrivacyPolicyLink(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(privacyPolicyUrl: '/legal/privacy-notice');
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringContainsString('/legal/privacy-notice', $html);
        self::assertStringContainsString('Privacy Policy', $html);
    }

    #[Test]
    public function renderAppliesPositionClass(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(position: 'top');
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringContainsString('pulsar-consent-top', $html);
    }

    #[Test]
    public function renderIncludesGranularCheckboxesWhenEnabled(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(
            categories: [
                new ConsentCategory('necessary', 'Necessary', 'Required', true, true),
                new ConsentCategory('analytics', 'Analytics', 'Tracking', false, false),
            ],
            granularOptIn: true,
        );
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringContainsString('pulsar-consent-categories', $html);
        self::assertStringContainsString('consent-necessary', $html);
        self::assertStringContainsString('consent-analytics', $html);
    }

    #[Test]
    public function renderOmitsCategoriesWhenGranularDisabled(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(granularOptIn: false);
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringNotContainsString('pulsar-consent-categories', $html);
    }

    #[Test]
    public function renderMarksRequiredCategoriesAsDisabled(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(
            categories: [
                new ConsentCategory('essential', 'Essential', 'Must have', true, true),
            ],
            granularOptIn: true,
        );
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringContainsString(' disabled', $html);
        self::assertStringContainsString(' checked', $html);
    }

    #[Test]
    public function renderChecksDefaultEnabledCategories(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(
            categories: [
                new ConsentCategory('prefs', 'Preferences', 'Settings', false, true),
            ],
            granularOptIn: true,
        );
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringContainsString(' checked', $html);
        self::assertStringNotContainsString(' disabled', $html);
    }

    #[Test]
    public function renderDoesNotCheckNonDefaultCategories(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(
            categories: [
                new ConsentCategory('marketing', 'Marketing', 'Ads', false, false),
            ],
            granularOptIn: true,
        );
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringContainsString('consent-marketing', $html);
        // The checkbox should not have "checked" since defaultEnabled=false and required=false
        self::assertStringNotContainsString(' checked', $html);
    }

    #[Test]
    public function renderEscapesXssInCategoryLabel(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(
            categories: [
                new ConsentCategory('xss', '<img onerror=alert(1)>', 'desc', false, false),
            ],
            granularOptIn: true,
        );
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringNotContainsString('<img onerror=alert(1)>', $html);
        self::assertStringContainsString('&lt;img onerror=alert(1)&gt;', $html);
    }

    #[Test]
    public function renderEscapesXssInPrivacyUrl(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(privacyPolicyUrl: '"><script>alert(1)</script>');
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    #[Test]
    public function renderIncludesCookieNameInScript(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(cookieName: 'my_gdpr_consent');
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringContainsString('my_gdpr_consent', $html);
    }

    #[Test]
    public function renderIncludesSavePreferencesButtonForGranular(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(granularOptIn: true);
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringContainsString('pulsar-consent-save', $html);
        self::assertStringContainsString('Save Preferences', $html);
    }

    #[Test]
    public function renderReferencesTheExternalConsentScript(): void
    {
        // Arrange
        $renderer = new ConsentBannerRenderer(new ConsentBannerConfig());

        // Act
        $html = $renderer->render();

        // Assert: the behaviour lives in a same-origin external script
        // (script-src 'self' clean); no inline consent logic is embedded, so the
        // banner works under the framework's default policy without a hash/nonce.
        self::assertStringContainsString('<script src="/ui/js/consent-banner.js"', $html);
        self::assertStringContainsString('data-consent="necessary"', $html);
        self::assertStringNotContainsString('saveConsent', $html);
        self::assertStringNotContainsString('document.cookie', $html);
    }

    #[Test]
    public function renderContainsCategoryDescription(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(
            categories: [
                new ConsentCategory('analytics', 'Analytics', 'Helps us understand usage', false, false),
            ],
            granularOptIn: true,
        );
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert
        self::assertStringContainsString('Helps us understand usage', $html);
    }

    /**
     * A cookieName containing a double-quote must be htmlspecialchars-escaped
     * into the data-cookie-name attribute so it cannot break out of the quoted
     * attribute; the browser still decodes it back to the original value.
     */
    #[Test]
    public function renderSafelyEncodesCookieNameContainingQuoteInDataAttribute(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(cookieName: 'na"me');
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert: the quote is emitted as &quot; (which decodes back to na"me in
        // the browser) and never as a raw quote that would break the attribute.
        self::assertStringContainsString('data-cookie-name="na&quot;me"', $html);
        self::assertStringNotContainsString('data-cookie-name="na"', $html);
    }

    /**
     * A category key containing `</script>` must be escaped inside the
     * data-categories attribute so it cannot break out of the attribute or
     * inject markup, while still round-tripping to its original value.
     */
    #[Test]
    public function renderSafelyEncodesScriptBreakoutInCategoryKey(): void
    {
        // Arrange
        $config = new ConsentBannerConfig(
            categories: [
                new ConsentCategory('a</script><b>', 'Label', 'Desc', false, false),
            ],
            granularOptIn: true,
        );
        $renderer = new ConsentBannerRenderer($config);

        // Act
        $html = $renderer->render();

        // Assert: extract the data-categories attribute and confirm the breakout
        // sequence is entity-escaped (no raw `<` or `</script>`), yet still
        // decodes and JSON-parses back to the original key.
        if (preg_match('/data-categories="([^"]*)"/', $html, $m) !== 1) {
            self::fail('data-categories attribute not found in rendered banner.');
        }
        $attr = $m[1];

        self::assertStringNotContainsString('</script>', $attr);
        self::assertStringNotContainsString('<', $attr);
        self::assertStringContainsString('&lt;', $attr);

        /** @var list<array{key: string}> $categories */
        $categories = json_decode(htmlspecialchars_decode($attr, ENT_QUOTES), true);
        self::assertIsArray($categories);
        self::assertSame('a</script><b>', $categories[0]['key']);
    }
}
