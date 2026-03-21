<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Locale-specific product content (name, description, slug).
 *
 * @psalm-api Domain DTO; instantiated by ProductRepository from
 *            translations table rows and returned to user-land code.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ProductTranslation
{
    /**
     * @param string $id UUIDv7
     * @param string $productId UUIDv7 of the parent product
     * @param string $locale BCP 47 locale code
     * @param string $name Localized product name
     * @param string $description Localized product description
     * @param string $slug URL-safe slug for this locale
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $locale,
        public string $name,
        public string $description,
        public string $slug,
    ) {}
}
