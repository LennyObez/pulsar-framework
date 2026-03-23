<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FieldRegistry;

use Pulsar\Api\Api;

/**
 * Value object representing a complete content type definition
 * with its associated custom fields.
 *
 * @psalm-api Public DTO produced by ContentTypeBuilder::build(); registered
 *            with ContentTypeRegistryInterface.
 */
#[Api(since: '1.0.0')]
final readonly class ContentTypeDefinition
{
    /**
     * @param string $type Machine identifier for the content type
     * @param string $label Human-readable display name
     * @param string $icon Icon identifier for admin UI
     * @param list<ContentTypeField> $fields Ordered list of custom field definitions
     */
    public function __construct(
        public string $type,
        public string $label,
        public string $icon,
        public array $fields,
    ) {}
}
