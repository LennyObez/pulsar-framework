<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Category;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Category\CategoryTranslation;

final class CategoryTranslationTest extends TestCase
{
    #[Test]
    public function createSetsAllFields(): void
    {
        $translation = CategoryTranslation::create(
            id: 'trans-1',
            categoryId: 'cat-1',
            locale: 'en',
            name: 'General',
            description: 'General discussion',
        );

        self::assertSame('trans-1', $translation->id);
        self::assertSame('cat-1', $translation->categoryId);
        self::assertSame('en', $translation->locale);
        self::assertSame('General', $translation->name);
        self::assertSame('General discussion', $translation->description);
    }

    #[Test]
    public function createDefaultsDescriptionToEmpty(): void
    {
        $translation = CategoryTranslation::create(
            id: 'trans-1',
            categoryId: 'cat-1',
            locale: 'fr',
            name: 'General',
        );

        self::assertSame('', $translation->description);
    }

    #[Test]
    public function updateChangesNameAndDescription(): void
    {
        $translation = CategoryTranslation::create(
            id: 'trans-1',
            categoryId: 'cat-1',
            locale: 'en',
            name: 'Old Name',
            description: 'Old desc',
        );

        $updated = $translation->update('New Name', 'New desc');

        self::assertSame('New Name', $updated->name);
        self::assertSame('New desc', $updated->description);
        self::assertSame('en', $updated->locale);
        self::assertSame('Old Name', $translation->name);
    }
}
