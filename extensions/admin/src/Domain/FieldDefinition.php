<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Domain;

use Pulsar\Api\Api;

/**
 * Defines a single field in an admin resource.
 */
#[Api(since: '1.0.0')]
final readonly class FieldDefinition
{
    /**
     * @param list<ValidationRule> $rules
     * @param list<string> $enumValues
     */
    public function __construct(
        public string $name,
        public FieldType $type,
        public string $label,
        public bool $sortable = false,
        public bool $filterable = false,
        public bool $searchable = false,
        public bool $redacted = false,
        public bool $exportable = true,
        public bool $editable = true,
        public bool $visibleOnList = true,
        public bool $visibleOnDetail = true,
        public bool $visibleOnForm = true,
        public array $rules = [],
        public array $enumValues = [],
        public ?string $relationResource = null,
        public ?string $placeholder = null,
        public ?string $helpText = null,
    ) {}
}
