<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Defines a configurable attribute for product variants (e.g. size, color).
 */
#[Api(since: '1.0.0')]
final readonly class ProductAttribute
{
    /**
     * @param string $id UUIDv7
     * @param string $productId UUIDv7 of the parent product
     * @param string $attributeKey Machine-readable key (e.g. "size", "color")
     * @param list<string> $allowedValues Valid values for this attribute
     * @param array<string, array<string, string>> $translations Locale => (value => translated label)
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $attributeKey,
        public array $allowedValues,
        public array $translations,
    ) {}
}
