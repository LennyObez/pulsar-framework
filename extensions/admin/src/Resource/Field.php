<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Resource;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ValidationRule;

/**
 * Fluent field builder for admin resource definitions.
 *
 * Each field type has a static factory (e.g., TextField::make('name')).
 * Chain modifiers to configure display, validation, and behavior:
 *
 *   TextField::make('name')
 *       ->label('Full Name')
 *       ->required()
 *       ->searchable()
 *       ->placeholder('Enter full name');
 * @api
 */
#[Api(since: '1.0.0')]
class Field
{
    public readonly string $fieldName;

    protected FieldType $type;

    protected string $fieldLabel = '';

    protected bool $isSortable = false;

    protected bool $isFilterable = false;

    protected bool $isSearchable = false;

    protected bool $isRedacted = false;

    public bool $isExportable = true;

    protected bool $isEditable = true;

    protected bool $showOnList = true;

    protected bool $showOnDetail = true;

    protected bool $showOnForm = true;

    /** @var list<ValidationRule> */
    protected array $rules = [];

    /** @var list<string> */
    protected array $enumValues = [];

    protected ?string $relationResource = null;

    protected ?string $placeholderText = null;

    protected ?string $helpTextContent = null;

    final public function __construct(string $name, FieldType $type)
    {
        $this->fieldName = $name;
        $this->type = $type;
        $this->fieldLabel = ucfirst(str_replace('_', ' ', $name));
    }

    public function label(string $label): static
    {
        $this->fieldLabel = $label;

        return $this;
    }

    public function sortable(bool $sortable = true): static
    {
        $this->isSortable = $sortable;

        return $this;
    }

    public function filterable(bool $filterable = true): static
    {
        $this->isFilterable = $filterable;

        return $this;
    }

    public function searchable(bool $searchable = true): static
    {
        $this->isSearchable = $searchable;

        return $this;
    }

    public function redacted(bool $redacted = true): static
    {
        $this->isRedacted = $redacted;

        return $this;
    }

    public function exportable(bool $exportable = true): static
    {
        $this->isExportable = $exportable;

        return $this;
    }

    public function readonly(bool $readonly = true): static
    {
        $this->isEditable = !$readonly;

        return $this;
    }

    public function hiddenOnList(bool $hidden = true): static
    {
        $this->showOnList = !$hidden;

        return $this;
    }

    public function hiddenOnDetail(bool $hidden = true): static
    {
        $this->showOnDetail = !$hidden;

        return $this;
    }

    public function hiddenOnForm(bool $hidden = true): static
    {
        $this->showOnForm = !$hidden;

        return $this;
    }

    public function required(?string $message = null): static
    {
        $this->rules[] = new ValidationRule(rule: 'required', message: $message);

        return $this;
    }

    public function minLength(int $length, ?string $message = null): static
    {
        $this->rules[] = new ValidationRule(rule: 'min_length', message: $message, parameter: $length);

        return $this;
    }

    public function maxLength(int $length, ?string $message = null): static
    {
        $this->rules[] = new ValidationRule(rule: 'max_length', message: $message, parameter: $length);

        return $this;
    }

    public function min(float|int $value, ?string $message = null): static
    {
        $this->rules[] = new ValidationRule(rule: 'min', message: $message, parameter: $value);

        return $this;
    }

    public function max(float|int $value, ?string $message = null): static
    {
        $this->rules[] = new ValidationRule(rule: 'max', message: $message, parameter: $value);

        return $this;
    }

    public function pattern(string $regex, ?string $message = null): static
    {
        $this->rules[] = new ValidationRule(rule: 'pattern', message: $message, parameter: $regex);

        return $this;
    }

    public function placeholder(string $text): static
    {
        $this->placeholderText = $text;

        return $this;
    }

    public function helpText(string $text): static
    {
        $this->helpTextContent = $text;

        return $this;
    }

    public function toFieldDefinition(): FieldDefinition
    {
        return new FieldDefinition(
            name: $this->fieldName,
            type: $this->type,
            label: $this->fieldLabel,
            sortable: $this->isSortable,
            filterable: $this->isFilterable,
            searchable: $this->isSearchable,
            redacted: $this->isRedacted,
            exportable: $this->isExportable,
            editable: $this->isEditable,
            visibleOnList: $this->showOnList,
            visibleOnDetail: $this->showOnDetail,
            visibleOnForm: $this->showOnForm,
            rules: $this->rules,
            enumValues: $this->enumValues,
            relationResource: $this->relationResource,
            placeholder: $this->placeholderText,
            helpText: $this->helpTextContent,
        );
    }
}
