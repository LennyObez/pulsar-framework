<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Category;

use Pulsar\Api\Api;

/**
 * Localized name and description for a forum category.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CategoryTranslation
{
    /**
     * @param string $id UUIDv7
     * @param string $categoryId UUIDv7 FK category
     * @param string $locale BCP 47 locale code
     * @param string $name Localized category name
     * @param string $description Localized category description
     */
    public function __construct(
        public string $id,
        public string $categoryId,
        public string $locale,
        public string $name,
        public string $description,
    ) {}

    /**
     * Create a new category translation.
     */
    public static function create(
        string $id,
        string $categoryId,
        string $locale,
        string $name,
        string $description = '',
    ): self {
        return new self(
            id: $id,
            categoryId: $categoryId,
            locale: $locale,
            name: $name,
            description: $description,
        );
    }

    /**
     * Update the translated name and description.
     *
     */
    public function update(string $name, string $description): self
    {
        return clone($this, [
            'name' => $name,
            'description' => $description,
        ]);
    }
}
