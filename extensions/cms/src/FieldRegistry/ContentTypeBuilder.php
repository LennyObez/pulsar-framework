<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FieldRegistry;

use Pulsar\Api\Api;

/**
 * Fluent builder for constructing content type definitions with custom fields.
 *
 * Usage:
 *     $builder = new ContentTypeBuilder('project');
 *     $definition = $builder
 *         ->label('Project')
 *         ->icon('code')
 *         ->field('tagline', FieldType::String, required: true, translatable: true)
 *         ->field('featured', FieldType::Bool, default: false)
 *         ->build();
 */
#[Api(since: '1.0.0')]
final class ContentTypeBuilder
{
    private string $label = '';
    private string $icon = '';

    /** @var list<ContentTypeField> */
    private array $fields = [];

    private int $nextSortOrder = 0;

    public function __construct(
        private readonly string $type,
    ) {}

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Add a custom field to this content type.
     *
     * @param array<string, mixed> $validation Validation constraints
     */
    public function field(
        string $key,
        FieldType $type,
        bool $required = false,
        bool $translatable = false,
        bool $searchable = false,
        bool $filterable = false,
        bool $sortable = false,
        array $validation = [],
        mixed $default = null,
    ): self {
        $this->fields[] = new ContentTypeField(
            id: '',
            contentType: $this->type,
            fieldKey: $key,
            fieldType: $type,
            required: $required,
            translatable: $translatable,
            searchable: $searchable,
            filterable: $filterable,
            sortable: $sortable,
            validationRules: $validation,
            defaultValue: $default,
            sortOrder: $this->nextSortOrder++,
        );

        return $this;
    }

    /**
     * Build the content type definition.
     */
    public function build(): ContentTypeDefinition
    {
        return new ContentTypeDefinition(
            type: $this->type,
            label: $this->label,
            icon: $this->icon,
            fields: $this->fields,
        );
    }
}
