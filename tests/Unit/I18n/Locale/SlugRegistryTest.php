<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Locale\SlugMatch;
use Pulsar\I18n\Locale\SlugRegistry;

#[CoversClass(SlugRegistry::class)]
#[CoversClass(SlugMatch::class)]
final class SlugRegistryTest extends TestCase
{
    /**
     * @return array<string, array<string, string>>
     */
    private function sampleSlugs(): array
    {
        return [
            'development' => ['fr' => 'developpement', 'nl' => 'ontwikkeling'],
            'development/projects' => ['fr' => 'developpement/projets', 'nl' => 'ontwikkeling/projecten'],
            'about' => ['fr' => 'a-propos'],
        ];
    }

    private function registry(): SlugRegistry
    {
        return SlugRegistry::fromConfig($this->sampleSlugs(), ['en', 'fr', 'nl']);
    }

    #[Test]
    public function empty_config_yields_empty_registry(): void
    {
        $registry = SlugRegistry::fromConfig([], ['en', 'fr']);

        self::assertTrue($registry->isEmpty());
        self::assertSame([], $registry->keys());
    }

    #[Test]
    public function registers_all_keys(): void
    {
        $registry = $this->registry();

        self::assertFalse($registry->isEmpty());
        self::assertTrue($registry->hasKey('development'));
        self::assertTrue($registry->hasKey('development/projects'));
        self::assertTrue($registry->hasKey('about'));
        self::assertFalse($registry->hasKey('unknown'));
        self::assertEqualsCanonicalizing(
            ['development', 'development/projects', 'about'],
            $registry->keys(),
        );
    }

    #[Test]
    public function key_lookup_normalizes_surrounding_slashes(): void
    {
        $registry = $this->registry();

        self::assertTrue($registry->hasKey('/development/'));
    }

    #[Test]
    public function slug_for_returns_canonical_translation(): void
    {
        $registry = $this->registry();

        self::assertSame('developpement', $registry->slugFor('development', 'fr'));
        self::assertSame('ontwikkeling', $registry->slugFor('development', 'nl'));
        self::assertSame('developpement/projets', $registry->slugFor('development/projects', 'fr'));
    }

    #[Test]
    public function slug_for_falls_back_to_key_when_locale_unspecified(): void
    {
        $registry = $this->registry();

        // 'about' has no nl slug.
        self::assertSame('about', $registry->slugFor('about', 'nl'));
        // Default locale 'en' is never declared, so it always falls back to the key.
        self::assertSame('development', $registry->slugFor('development', 'en'));
    }

    #[Test]
    public function match_localized_resolves_canonical_slug(): void
    {
        $match = $this->registry()->matchLocalized('fr', '/developpement');

        self::assertNotNull($match);
        self::assertSame('development', $match->key);
        self::assertSame('', $match->remainder);
        self::assertTrue($match->isCanonical);
    }

    #[Test]
    public function match_localized_flags_key_alias_as_non_canonical(): void
    {
        // The bare key under fr is a valid alias but not the canonical slug.
        $match = $this->registry()->matchLocalized('fr', '/development');

        self::assertNotNull($match);
        self::assertSame('development', $match->key);
        self::assertFalse($match->isCanonical);
    }

    #[Test]
    public function match_localized_prefers_longest_prefix(): void
    {
        $match = $this->registry()->matchLocalized('fr', '/developpement/projets/my-project');

        self::assertNotNull($match);
        self::assertSame('development/projects', $match->key);
        self::assertSame('my-project', $match->remainder);
        self::assertTrue($match->isCanonical);
    }

    #[Test]
    public function match_localized_extracts_remainder_for_single_segment_slug(): void
    {
        // 'developpement' (1 segment) with trailing params that are not a registered sub-slug.
        $match = $this->registry()->matchLocalized('fr', '/developpement/anything-else');

        self::assertNotNull($match);
        self::assertSame('development', $match->key);
        self::assertSame('anything-else', $match->remainder);
    }

    #[Test]
    public function match_localized_returns_null_for_unknown_path(): void
    {
        self::assertNull($this->registry()->matchLocalized('fr', '/contact'));
        self::assertNull($this->registry()->matchLocalized('fr', '/'));
    }

    #[Test]
    public function match_localized_returns_null_for_locale_without_forms(): void
    {
        self::assertNull($this->registry()->matchLocalized('de', '/developpement'));
    }

    #[Test]
    public function match_localized_canonical_when_locale_falls_back_to_key(): void
    {
        // 'about' has no nl slug, so '/about' under nl is the canonical form (no redirect).
        $match = $this->registry()->matchLocalized('nl', '/about');

        self::assertNotNull($match);
        self::assertSame('about', $match->key);
        self::assertTrue($match->isCanonical);
    }

    #[Test]
    public function match_key_resolves_canonical_key_path(): void
    {
        $match = $this->registry()->matchKey('/development/projects/my-project');

        self::assertNotNull($match);
        self::assertSame('development/projects', $match->key);
        self::assertSame('my-project', $match->remainder);
        self::assertTrue($match->isCanonical);
    }

    #[Test]
    public function match_key_returns_null_for_unknown_path(): void
    {
        self::assertNull($this->registry()->matchKey('/nope'));
    }

    #[Test]
    public function declared_slugs_excludes_key_fallbacks(): void
    {
        $declared = $this->registry()->declaredSlugs();

        self::assertSame(['fr' => 'developpement', 'nl' => 'ontwikkeling'], $declared['development']);
        self::assertSame(['fr' => 'a-propos'], $declared['about']);
        // 'en' fallbacks are never declared.
        self::assertArrayNotHasKey('en', $declared['development']);
    }

    #[Test]
    public function supported_locales_are_retained(): void
    {
        self::assertSame(['en', 'fr', 'nl'], $this->registry()->supportedLocales());
    }

    #[Test]
    public function malformed_entries_are_dropped(): void
    {
        $registry = SlugRegistry::fromConfig(
            [
                'valid' => ['fr' => 'valide'],
                '' => ['fr' => 'empty-key'],
                'noslugs' => [],
            ],
            ['en', 'fr'],
        );

        self::assertTrue($registry->hasKey('valid'));
        self::assertFalse($registry->hasKey(''));
        // A key with no usable slugs still registers (it can be matched by its key path).
        self::assertSame('noslugs', $registry->slugFor('noslugs', 'fr'));
    }
}
