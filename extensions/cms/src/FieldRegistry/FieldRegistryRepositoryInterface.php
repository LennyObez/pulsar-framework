<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FieldRegistry;

use Pulsar\Api\Api;

/**
 * Persistence interface for custom field definitions and values.
 *
 * @psalm-api Public binding contract; implemented by DbFieldRegistryRepository
 *            and consumed by content services and admin controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface FieldRegistryRepositoryInterface
{
    /**
     * Find all field definitions for a content type.
     *
     * @return list<ContentTypeField>
     */
    public function findFieldsByContentType(string $contentType): array;

    /**
     * Persist a field definition.
     */
    public function saveField(ContentTypeField $field): void;

    /**
     * Persist a field value.
     */
    public function saveValue(ContentFieldValue $value): void;

    /**
     * Find all field values for a content item, optionally filtered by locale.
     *
     * @return list<ContentFieldValue>
     */
    public function findValues(string $contentId, ?string $locale = null): array;
}
