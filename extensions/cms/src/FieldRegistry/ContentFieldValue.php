<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FieldRegistry;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Stores the actual value of a custom field for a content item.
 *
 * Only the column matching the field's type is populated; all others remain null.
 * This enables proper indexing and type-safe queries.
 *
 * @psalm-api Public DTO returned from FieldRegistryRepositoryInterface; consumed
 *            by content rendering and admin templates.
 */
#[Api(since: '1.0.0')]
final readonly class ContentFieldValue
{
    /**
     * @param string $id UUIDv7
     * @param string $contentId UUIDv7 FK content
     * @param string $fieldId UUIDv7 FK content_type_fields
     * @param string|null $locale BCP 47 locale code, null if field is non-translatable
     * @param string|null $valueString Used for string, enum, url, email, color field types
     * @param int|null $valueInt Used for int field type
     * @param float|null $valueFloat Used for float field type
     * @param bool|null $valueBool Used for bool field type
     * @param DateTimeImmutable|null $valueDatetime Used for date and datetime field types
     * @param array<string, mixed>|null $valueJson Used for json, relation, media, rich_text field types
     */
    public function __construct(
        public string $id,
        public string $contentId,
        public string $fieldId,
        public ?string $locale,
        public ?string $valueString,
        public ?int $valueInt,
        public ?float $valueFloat,
        public ?bool $valueBool,
        public ?DateTimeImmutable $valueDatetime,
        public ?array $valueJson,
    ) {}
}
