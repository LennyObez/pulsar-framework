<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\ConsentBanner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\ConsentBanner\ConsentBannerConfig;
use Pulsar\DataProtection\ConsentBanner\ConsentCategory;

#[CoversClass(ConsentBannerConfig::class)]
final class ConsentBannerConfigTest extends TestCase
{
    #[Test]
    public function defaultConstructorValues(): void
    {
        $config = new ConsentBannerConfig();

        self::assertTrue($config->enabled);
        self::assertSame('bottom', $config->position);
        self::assertSame('/privacy', $config->privacyPolicyUrl);
        self::assertSame([], $config->categories);
        self::assertTrue($config->granularOptIn);
        self::assertSame('pulsar_consent', $config->cookieName);
        self::assertSame(365, $config->cookieTtlDays);
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $cats = [new ConsentCategory('test', 'Test', 'Testing', false, false)];
        $config = new ConsentBannerConfig(
            enabled: false,
            position: 'top',
            privacyPolicyUrl: '/legal/privacy',
            categories: $cats,
            granularOptIn: false,
            cookieName: 'gdpr_consent',
            cookieTtlDays: 180,
        );

        self::assertFalse($config->enabled);
        self::assertSame('top', $config->position);
        self::assertSame('/legal/privacy', $config->privacyPolicyUrl);
        self::assertCount(1, $config->categories);
        self::assertFalse($config->granularOptIn);
        self::assertSame('gdpr_consent', $config->cookieName);
        self::assertSame(180, $config->cookieTtlDays);
    }

    #[Test]
    public function fromArrayPopulatesDefaultCategories(): void
    {
        $config = ConsentBannerConfig::fromArray([]);

        self::assertCount(4, $config->categories);
        self::assertSame('necessary', $config->categories[0]->key);
        self::assertSame('analytics', $config->categories[1]->key);
        self::assertSame('marketing', $config->categories[2]->key);
        self::assertSame('preferences', $config->categories[3]->key);
    }

    #[Test]
    public function fromArrayDefaultCategoriesHaveCorrectFlags(): void
    {
        $config = ConsentBannerConfig::fromArray([]);

        // Necessary is required and default-enabled
        self::assertTrue($config->categories[0]->required);
        self::assertTrue($config->categories[0]->defaultEnabled);

        // Others are optional and not default-enabled
        self::assertFalse($config->categories[1]->required);
        self::assertFalse($config->categories[1]->defaultEnabled);
    }

    #[Test]
    public function fromArrayParsesCustomCategories(): void
    {
        $config = ConsentBannerConfig::fromArray([
            'categories' => [
                'essential' => [
                    'label' => 'Essential',
                    'description' => 'Required cookies',
                    'required' => true,
                    'default_enabled' => true,
                ],
                'performance' => [
                    'label' => 'Performance',
                    'description' => 'Performance tracking',
                ],
            ],
        ]);

        self::assertCount(2, $config->categories);
        self::assertSame('essential', $config->categories[0]->key);
        self::assertTrue($config->categories[0]->required);
        self::assertSame('performance', $config->categories[1]->key);
        self::assertFalse($config->categories[1]->required);
    }

    #[Test]
    public function fromArraySkipsNonArrayCategoryEntries(): void
    {
        $config = ConsentBannerConfig::fromArray([
            'categories' => [
                'valid' => ['label' => 'Valid'],
                'invalid' => 'not-an-array',
                'also_invalid' => 42,
            ],
        ]);

        self::assertCount(1, $config->categories);
        self::assertSame('valid', $config->categories[0]->key);
    }

    #[Test]
    public function fromArrayFallsBackToDefaultsForNonBoolEnabled(): void
    {
        $config = ConsentBannerConfig::fromArray([
            'enabled' => 'yes',
            'granular_opt_in' => 1,
        ]);

        self::assertTrue($config->enabled);
        self::assertTrue($config->granularOptIn);
    }

    #[Test]
    public function fromArrayHandlesNonArrayCategoriesGracefully(): void
    {
        $config = ConsentBannerConfig::fromArray([
            'categories' => 'not-an-array',
        ]);

        // Falls back to default categories
        self::assertCount(4, $config->categories);
    }

    #[Test]
    public function fromArrayParsesNonStringPositionAsDefault(): void
    {
        $config = ConsentBannerConfig::fromArray([
            'position' => 42,
            'privacy_policy_url' => false,
            'cookie_name' => [],
        ]);

        self::assertSame('bottom', $config->position);
        self::assertSame('/privacy', $config->privacyPolicyUrl);
        self::assertSame('pulsar_consent', $config->cookieName);
    }

    #[Test]
    public function fromArrayParsesIntCookieTtlDays(): void
    {
        $config = ConsentBannerConfig::fromArray([
            'cookie_ttl_days' => 90,
        ]);

        self::assertSame(90, $config->cookieTtlDays);
    }

    #[Test]
    public function fromArrayDefaultsNonIntCookieTtlDays(): void
    {
        $config = ConsentBannerConfig::fromArray([
            'cookie_ttl_days' => 'not-a-number',
        ]);

        self::assertSame(365, $config->cookieTtlDays);
    }
}
