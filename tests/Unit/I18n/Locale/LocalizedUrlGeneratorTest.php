<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\Locale\HreflangLink;
use Pulsar\I18n\Locale\LocaleUrlStrategy;
use Pulsar\I18n\Locale\LocalizedUrlGenerator;
use Pulsar\I18n\Locale\SlugRegistry;
use Pulsar\I18n\Locale\UrlPrefixExtractor;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\Routing\Router;

#[CoversClass(LocalizedUrlGenerator::class)]
final class LocalizedUrlGeneratorTest extends TestCase
{
    /** @var TranslatorInterface&object{locale: string} */
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        $this->translator = new class implements TranslatorInterface {
            public string $locale = 'en';

            public function translate(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
            {
                return $key;
            }

            public function has(string $key, ?string $locale = null, string $domain = 'messages'): bool
            {
                return false;
            }
        };
    }

    protected function tearDown(): void
    {
        LocalizedUrlGenerator::resetGlobalInstance();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function slugs(): array
    {
        return [
            'development' => ['fr' => 'developpement', 'nl' => 'ontwikkeling'],
            'development/projects' => ['fr' => 'developpement/projets', 'nl' => 'ontwikkeling/projecten'],
            'about' => ['fr' => 'a-propos'],
        ];
    }

    private function makeGenerator(bool $defaultLocaleInUrl = false, ?Router $router = null): LocalizedUrlGenerator
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
            defaultLocaleInUrl: $defaultLocaleInUrl,
            canonicalRedirect: true,
            localizedSlugs: $this->slugs(),
        );

        return new LocalizedUrlGenerator(
            SlugRegistry::fromConfig($this->slugs(), $config->supportedLocales),
            $config,
            new UrlPrefixExtractor(),
            $this->translator,
            $router,
        );
    }

    #[Test]
    public function builds_localized_url_for_explicit_locale(): void
    {
        $generator = $this->makeGenerator();

        self::assertSame('/fr/developpement', $generator->route('development', [], 'fr'));
        self::assertSame('/nl/ontwikkeling', $generator->route('development', [], 'nl'));
    }

    #[Test]
    public function default_locale_has_no_prefix_and_uses_key(): void
    {
        $generator = $this->makeGenerator();

        self::assertSame('/development', $generator->route('development', [], 'en'));
    }

    #[Test]
    public function uses_current_locale_when_none_given(): void
    {
        $generator = $this->makeGenerator();
        $this->translator->locale = 'fr';

        self::assertSame('/fr/developpement', $generator->route('development'));
    }

    #[Test]
    public function falls_back_to_key_for_locale_without_slug(): void
    {
        $generator = $this->makeGenerator();

        // 'about' has no nl slug → key, prefixed.
        self::assertSame('/nl/about', $generator->route('about', [], 'nl'));
    }

    #[Test]
    public function default_locale_in_url_prefixes_default(): void
    {
        $generator = $this->makeGenerator(defaultLocaleInUrl: true);

        self::assertSame('/en/development', $generator->route('development', [], 'en'));
    }

    #[Test]
    public function fills_route_parameters_and_swaps_slug_prefix(): void
    {
        $router = new Router();
        $router->get('/development/projects/{slug}', 'Handler', 'development/projects');

        $generator = $this->makeGenerator(router: $router);

        self::assertSame(
            '/fr/developpement/projets/my-project',
            $generator->route('development/projects', ['slug' => 'my-project'], 'fr'),
        );
        self::assertSame(
            '/development/projects/my-project',
            $generator->route('development/projects', ['slug' => 'my-project'], 'en'),
        );
    }

    #[Test]
    public function alternates_covers_all_locales(): void
    {
        $generator = $this->makeGenerator();

        self::assertSame(
            [
                'en' => '/development',
                'fr' => '/fr/developpement',
                'nl' => '/nl/ontwikkeling',
            ],
            $generator->alternates('development'),
        );
    }

    #[Test]
    public function hreflang_links_include_x_default(): void
    {
        $generator = $this->makeGenerator();

        $links = $generator->hreflangLinks('development');

        self::assertCount(4, $links);

        $byLocale = [];
        foreach ($links as $link) {
            self::assertInstanceOf(HreflangLink::class, $link);
            $byLocale[$link->locale] = $link->href;
        }

        self::assertSame('/development', $byLocale['en']);
        self::assertSame('/fr/developpement', $byLocale['fr']);
        self::assertSame('/nl/ontwikkeling', $byLocale['nl']);
        self::assertSame('/development', $byLocale['x-default']);
    }

    #[Test]
    public function global_instance_round_trips(): void
    {
        $generator = $this->makeGenerator();

        LocalizedUrlGenerator::setGlobalInstance($generator);

        self::assertSame($generator, LocalizedUrlGenerator::getGlobalInstance());

        LocalizedUrlGenerator::resetGlobalInstance();
    }
}
