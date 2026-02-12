<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Category;

use Pulsar\Api\Api;

/**
 * Repository interface for category translations.
 */
#[Api(since: '1.0.0')]
interface CategoryTranslationRepositoryInterface
{
    public function findById(string $id): ?CategoryTranslation;

    /**
     * Find the translation for a category in a specific locale.
     */
    public function findByCategoryAndLocale(string $categoryId, string $locale): ?CategoryTranslation;

    /**
     * Find all translations for a category.
     *
     * @return list<CategoryTranslation>
     */
    public function findByCategory(string $categoryId): array;

    public function save(CategoryTranslation $translation): void;

    public function delete(CategoryTranslation $translation): void;
}
