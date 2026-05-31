<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\Locale\LocaleUrlStrategy;
use Pulsar\I18n\Locale\SlugLocaleUrlResolver;
use Pulsar\I18n\Locale\SlugRegistry;
use Pulsar\I18n\Locale\UrlPrefixExtractor;

#[CoversClass(SlugLocaleUrlResolver::class)]
final class SlugLocaleUrlResolverTest extends TestCase
{
    /**
     * @return array<string, array<string, string>>
     */
    private function slugs(): array
    {
        return [
            'development' => ['fr' => 'developpement', 'nl' => 'ontwikkeling'],
            'development/projects' => ['fr' => 'developpement/projets', 'nl' => 'ontwikkeling/projecten'],
        ];
    }

    private function makeResolver(): SlugLocaleUrlResolver
    {
        $config = new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr', 'nl'],
            fallbackLocales: ['en'],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
            urlStrategy: LocaleUrlStrategy::PathPrefix,
            defaultLocaleInUrl: false,
            canonicalRedirect: true,
            localizedSlugs: $this->slugs(),
        );

        return new SlugLocaleUrlResolver(
            SlugRegistry::fromConfig($this->slugs(), $config->supportedLocales),
            $config,
            new UrlPrefixExtractor(),
        );
    }

    #[Test]
    public function resolves_localized_alternates_from_canonical_key_path(): void
    {
        $alternates = $this->makeResolver()->resolveAlternates('/development', 'en');

        self::assertSame(
            [
                'en' => '/development',
                'fr' => '/fr/developpement',
                'nl' => '/nl/ontwikkeling',
            ],
            $alternates,
        );
    }

    #[Test]
    public function resolves_alternates_preserving_remainder(): void
    {
        $alternates = $this->makeResolver()->resolveAlternates('/development/projects/my-project', 'en');

        self::assertSame(
            [
                'en' => '/development/projects/my-project',
                'fr' => '/fr/developpement/projets/my-project',
                'nl' => '/nl/ontwikkeling/projecten/my-project',
            ],
            $alternates,
        );
    }

    #[Test]
    public function resolves_alternates_from_a_localized_slug_path(): void
    {
        // Caller passes the fr localized path; resolver maps it back to the key.
        $alternates = $this->makeResolver()->resolveAlternates('/developpement', 'fr');

        self::assertSame('/development', $alternates['en']);
        self::assertSame('/fr/developpement', $alternates['fr']);
        self::assertSame('/nl/ontwikkeling', $alternates['nl']);
    }

    #[Test]
    public function falls_back_to_prefix_swap_for_unknown_path(): void
    {
        $alternates = $this->makeResolver()->resolveAlternates('/contact', 'en');

        self::assertSame(
            [
                'en' => '/contact',
                'fr' => '/fr/contact',
                'nl' => '/nl/contact',
            ],
            $alternates,
        );
    }
}
