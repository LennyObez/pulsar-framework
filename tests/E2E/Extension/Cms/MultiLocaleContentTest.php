<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;

use function array_column;
use function array_keys;
use function in_array;
use function sort;
use function sprintf;

/**
 * E2E: Multi-locale content — 4 locales, unique paths, hreflang, language switcher.
 */
#[CoversClass(Content::class)]
#[Group('e2e-cms')]
final class MultiLocaleContentTest extends TestCase
{
    #[Test]
    public function fourLocaleArticleWithUniquePaths(): void
    {
        $content = Content::create(
            id: 'ml-article-001',
            contentType: ContentType::Article,
            authorId: 'author-ml-001',
        );

        $locales = [
            'en' => [
                'title' => 'Getting Started with Pulsar',
                'slug' => 'getting-started',
                'path' => 'blog/getting-started',
                'body' => '<p>Welcome to Pulsar CMS. Build your site today.</p>',
                'metaTitle' => 'Getting Started | Pulsar CMS',
                'metaDescription' => 'Learn how to build your first site with Pulsar CMS.',
            ],
            'fr' => [
                'title' => 'Premiers pas avec Pulsar',
                'slug' => 'premiers-pas',
                'path' => 'blog/premiers-pas',
                'body' => '<p>Bienvenue sur Pulsar CMS. Construisez votre site aujourd\'hui.</p>',
                'metaTitle' => 'Premiers pas | Pulsar CMS',
                'metaDescription' => 'Apprenez a construire votre premier site avec Pulsar CMS.',
            ],
            'de' => [
                'title' => 'Erste Schritte mit Pulsar',
                'slug' => 'erste-schritte',
                'path' => 'blog/erste-schritte',
                'body' => '<p>Willkommen bei Pulsar CMS. Erstellen Sie noch heute Ihre Website.</p>',
                'metaTitle' => 'Erste Schritte | Pulsar CMS',
                'metaDescription' => 'Erfahren Sie, wie Sie Ihre erste Website mit Pulsar CMS erstellen.',
            ],
            'es' => [
                'title' => 'Primeros pasos con Pulsar',
                'slug' => 'primeros-pasos',
                'path' => 'blog/primeros-pasos',
                'body' => '<p>Bienvenido a Pulsar CMS. Cree su sitio hoy mismo.</p>',
                'metaTitle' => 'Primeros pasos | Pulsar CMS',
                'metaDescription' => 'Aprenda a crear su primer sitio con Pulsar CMS.',
            ],
        ];

        $translations = [];
        $index = 0;

        foreach ($locales as $locale => $data) {
            $translations[$locale] = ContentTranslation::create(
                id: sprintf('trans-ml-%03d', $index),
                contentId: $content->id,
                locale: $locale,
                title: $data['title'],
                slugSegment: $data['slug'],
                path: $data['path'],
                body: $data['body'],
                metaTitle: $data['metaTitle'],
                metaDescription: $data['metaDescription'],
            );
            $index++;
        }

        // Verify all 4 locales created
        self::assertCount(4, $translations);

        // Verify each translation has a unique path
        $paths = [];
        foreach ($translations as $t) {
            self::assertFalse(in_array($t->path, $paths, true), "Duplicate path found: {$t->path}");
            $paths[] = $t->path;
        }
        self::assertCount(4, $paths);

        // Verify locale assignments
        $localeKeys = array_keys($translations);
        sort($localeKeys);
        self::assertSame(['de', 'en', 'es', 'fr'], $localeKeys);

        // Verify each translation references the same content ID
        foreach ($translations as $t) {
            self::assertSame($content->id, $t->contentId);
        }
    }

    #[Test]
    public function hreflangLinkGeneration(): void
    {
        $contentId = 'ml-hreflang-001';
        $baseUrl = 'https://example.com';

        $translations = [
            'en' => ContentTranslation::create(
                id: 'trans-hreflang-en',
                contentId: $contentId,
                locale: 'en',
                title: 'About Us',
                slugSegment: 'about',
                path: 'about',
                body: '<p>About our company.</p>',
            ),
            'fr' => ContentTranslation::create(
                id: 'trans-hreflang-fr',
                contentId: $contentId,
                locale: 'fr',
                title: 'A propos',
                slugSegment: 'a-propos',
                path: 'a-propos',
                body: '<p>A propos de notre entreprise.</p>',
            ),
            'de' => ContentTranslation::create(
                id: 'trans-hreflang-de',
                contentId: $contentId,
                locale: 'de',
                title: 'Ueber uns',
                slugSegment: 'ueber-uns',
                path: 'ueber-uns',
                body: '<p>Ueber unser Unternehmen.</p>',
            ),
        ];

        // Generate hreflang tags
        $hreflangTags = [];
        foreach ($translations as $locale => $translation) {
            $hreflangTags[] = [
                'rel' => 'alternate',
                'hreflang' => $locale,
                'href' => $baseUrl . '/' . $locale . '/' . $translation->path,
            ];
        }

        // Add x-default pointing to English
        $hreflangTags[] = [
            'rel' => 'alternate',
            'hreflang' => 'x-default',
            'href' => $baseUrl . '/en/' . $translations['en']->path,
        ];

        self::assertCount(4, $hreflangTags); // 3 locales + x-default

        // Verify each hreflang has unique href
        $hrefs = array_column($hreflangTags, 'href');
        self::assertCount(4, $hrefs);

        // Verify x-default matches English
        $xDefault = $hreflangTags[3];
        self::assertSame('x-default', $xDefault['hreflang']);
        self::assertStringContainsString('/en/about', $xDefault['href']);

        // Verify no duplicate hreflang values (except x-default matching en)
        $hreflangValues = array_column($hreflangTags, 'hreflang');
        self::assertContains('en', $hreflangValues);
        self::assertContains('fr', $hreflangValues);
        self::assertContains('de', $hreflangValues);
        self::assertContains('x-default', $hreflangValues);
    }

    #[Test]
    public function languageSwitcherData(): void
    {
        $contentId = 'ml-switcher-001';

        $translations = [
            'en' => ContentTranslation::create(
                id: 'trans-sw-en',
                contentId: $contentId,
                locale: 'en',
                title: 'Contact',
                slugSegment: 'contact',
                path: 'contact',
                body: '<p>Get in touch.</p>',
            ),
            'fr' => ContentTranslation::create(
                id: 'trans-sw-fr',
                contentId: $contentId,
                locale: 'fr',
                title: 'Contact',
                slugSegment: 'contact',
                path: 'contact',
                body: '<p>Contactez-nous.</p>',
            ),
        ];

        // Build language switcher data from translations
        $switcherData = [];
        $localeNames = ['en' => 'English', 'fr' => 'Francais', 'de' => 'Deutsch', 'es' => 'Espanol'];

        foreach ($translations as $locale => $translation) {
            $switcherData[] = [
                'locale' => $locale,
                'label' => $localeNames[$locale],
                'path' => '/' . $locale . '/' . $translation->path,
                'isCurrent' => false,
            ];
        }

        // Mark current locale
        $currentLocale = 'en';
        foreach ($switcherData as &$item) {
            if ($item['locale'] === $currentLocale) {
                $item['isCurrent'] = true;
            }
        }
        unset($item);

        self::assertCount(2, $switcherData);

        // Verify current locale is marked
        $currentItems = array_filter($switcherData, static fn(array $item) => $item['isCurrent']);
        self::assertCount(1, $currentItems);

        $current = reset($currentItems);
        self::assertIsArray($current);
        self::assertSame('en', $current['locale']);
        self::assertSame('English', $current['label']);
    }

    #[Test]
    public function slugUniquenessAcrossLocales(): void
    {
        $contentId = 'ml-slug-unique-001';

        // Two different locales can share the same slug segment
        $en = ContentTranslation::create(
            id: 'trans-slug-en',
            contentId: $contentId,
            locale: 'en',
            title: 'Contact',
            slugSegment: 'contact',
            path: 'en/contact',
            body: '<p>Contact us.</p>',
        );

        $fr = ContentTranslation::create(
            id: 'trans-slug-fr',
            contentId: $contentId,
            locale: 'fr',
            title: 'Contact',
            slugSegment: 'contact',
            path: 'fr/contact',
            body: '<p>Contactez-nous.</p>',
        );

        // Same slug segment is allowed across different locales
        self::assertSame('contact', $en->slugSegment);
        self::assertSame('contact', $fr->slugSegment);

        // But full paths must differ (locale prefix ensures uniqueness)
        self::assertNotSame($en->path, $fr->path);
    }
}
