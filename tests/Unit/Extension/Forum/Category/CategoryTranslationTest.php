<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Category;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Category\CategoryTranslation;

#[CoversClass(CategoryTranslation::class)]
final class CategoryTranslationTest extends TestCase
{
    #[Test]
    public function createBuildsInstanceWithAllFields(): void
    {
        $translation = CategoryTranslation::create(
            id: 'ct-1',
            categoryId: 'cat-1',
            locale: 'fr-FR',
            name: 'G&eacute;n&eacute;ral',
            description: 'La cat&eacute;gorie g&eacute;n&eacute;rale',
        );

        self::assertSame('ct-1', $translation->id);
        self::assertSame('cat-1', $translation->categoryId);
        self::assertSame('fr-FR', $translation->locale);
        self::assertSame('G&eacute;n&eacute;ral', $translation->name);
        self::assertSame('La cat&eacute;gorie g&eacute;n&eacute;rale', $translation->description);
    }

    #[Test]
    public function createDefaultsDescriptionToEmptyString(): void
    {
        $translation = CategoryTranslation::create(
            id: 'ct-2',
            categoryId: 'cat-2',
            locale: 'en-US',
            name: 'General',
        );

        self::assertSame('', $translation->description);
    }

    #[Test]
    public function updateReturnsNewInstanceWithChangedNameAndDescription(): void
    {
        $original = CategoryTranslation::create(
            id: 'ct-3',
            categoryId: 'cat-3',
            locale: 'de-DE',
            name: 'Allgemein',
            description: 'Allgemeine Kategorie',
        );

        $updated = $original->update('Aktualisiert', 'Neue Beschreibung');

        self::assertSame('Aktualisiert', $updated->name);
        self::assertSame('Neue Beschreibung', $updated->description);
    }

    #[Test]
    public function updatePreservesIdCategoryIdAndLocale(): void
    {
        $original = CategoryTranslation::create(
            id: 'ct-4',
            categoryId: 'cat-4',
            locale: 'ja-JP',
            name: 'Original',
            description: 'Original desc',
        );

        $updated = $original->update('New Name', 'New Desc');

        self::assertSame($original->id, $updated->id);
        self::assertSame($original->categoryId, $updated->categoryId);
        self::assertSame($original->locale, $updated->locale);
    }

    #[Test]
    public function updateToEmptyStringsIsAllowed(): void
    {
        $original = CategoryTranslation::create(
            id: 'ct-5',
            categoryId: 'cat-5',
            locale: 'en-US',
            name: 'Has Name',
            description: 'Has Desc',
        );

        $updated = $original->update('', '');

        self::assertSame('', $updated->name);
        self::assertSame('', $updated->description);
    }
}
