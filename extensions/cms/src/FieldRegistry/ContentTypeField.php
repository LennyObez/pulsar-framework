<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FieldRegistry;

use Pulsar\Api\Api;

/**
 * Defines a structured custom field for a content type.
 *
 * Each field has a type that maps to a specific database value column
 * for proper indexing and type-safe queries.
 *
 * @psalm-api Public DTO returned from FieldRegistryRepositoryInterface and
 *            ContentTypeBuilder; consumed by admin form rendering.
 */
#[Api(since: '1.0.0')]
final readonly class ContentTypeField
{
    /**
     * @param string $id UUIDv7
     * @param string $contentType Which content type this field belongs to
     * @param string $fieldKey Machine name, unique per content_type
     * @param FieldType $fieldType Typed field type determining storage column
     * @param bool $required Whether the field must have a value
     * @param bool $translatable Whether the value differs per locale
     * @param bool $searchable Whether to include in search index
     * @param bool $filterable Whether available as admin list filter
     * @param bool $sortable Whether available as admin list sort
     * @param array<string, mixed> $validationRules Validation constraints (e.g., min, max, pattern)
     * @param mixed $defaultValue Default value when none provided
     * @param int $sortOrder Display order in admin form
     */
    public function __construct(
        public string $id,
        public string $contentType,
        public string $fieldKey,
        public FieldType $fieldType,
        public bool $required,
        public bool $translatable,
        public bool $searchable,
        public bool $filterable,
        public bool $sortable,
        public array $validationRules,
        public mixed $defaultValue,
        public int $sortOrder,
    ) {}
}
