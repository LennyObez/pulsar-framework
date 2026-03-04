<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\ContentType;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeBuilder;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeDefinition;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeField;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeRegistryInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldType;

use function array_key_exists;
use function array_map;
use function count;
use function is_array;
use function is_string;
use function preg_match;
use function strtolower;
use function trim;

/**
 * GUI-driven schema builder for creating content types visually.
 *
 * Accepts a structured definition (from an admin UI form) and produces
 * a validated ContentTypeDefinition. This is the backend counterpart
 * to the visual schema builder admin panel.
 *
 * Workflow:
 *   1. Admin submits field definitions via the GUI
 *   2. SchemaBuilder validates and constructs a ContentTypeDefinition
 *   3. The definition is persisted through the ContentTypeRegistryInterface
 */
#[Api(since: '1.0.0')]
final readonly class SchemaBuilder
{
    /**
     * Maximum number of fields allowed per content type.
     */
    private const int MAX_FIELDS = 100;

    /**
     * Allowed characters pattern for content type slugs.
     */
    private const string SLUG_PATTERN = '/^[a-z][a-z0-9_]{1,62}[a-z0-9]$/';

    public function __construct(
        private ContentTypeRegistryInterface $registry,
    ) {}

    /**
     * Build a content type definition from a structured input array.
     *
     * Expected structure:
     *   [
     *     'type'   => 'project',
     *     'label'  => 'Project',
     *     'icon'   => 'folder',
     *     'fields' => [
     *       ['key' => 'tagline', 'type' => 'string', 'required' => true, ...],
     *       ['key' => 'featured', 'type' => 'bool', 'default' => false, ...],
     *     ],
     *   ]
     *
     * @param array<string, mixed> $input Structured definition from the admin GUI
     *
     * @throws InvalidArgumentException On validation failure
     */
    public function buildFromInput(array $input): ContentTypeDefinition
    {
        $type = $this->extractString($input, 'type');
        $label = $this->extractString($input, 'label');
        $icon = is_string($input['icon'] ?? null) ? $input['icon'] : '';

        $this->validateSlug($type);

        /** @var mixed $rawFields */
        $rawFields = $input['fields'] ?? [];

        if (!is_array($rawFields)) {
            throw new InvalidArgumentException('Fields must be an array');
        }

        if (count($rawFields) > self::MAX_FIELDS) {
            throw new InvalidArgumentException('Content types cannot exceed ' . self::MAX_FIELDS . ' fields');
        }

        $builder = new ContentTypeBuilder($type);
        $builder->label($label)->icon($icon);

        $seenKeys = [];

        /** @var mixed $rawField */
        foreach ($rawFields as $rawField) {
            if (!is_array($rawField)) {
                throw new InvalidArgumentException('Each field definition must be an array');
            }

            /** @var array<string, mixed> $fieldData */
            $fieldData = $rawField;
            $this->addFieldFromInput($builder, $fieldData, $seenKeys);
        }

        return $builder->build();
    }

    /**
     * Build and register the content type in one step.
     *
     * @param array<string, mixed> $input
     *
     * @throws InvalidArgumentException On validation failure or duplicate type
     */
    public function buildAndRegister(array $input): ContentTypeDefinition
    {
        $definition = $this->buildFromInput($input);

        $existing = $this->registry->get($definition->type);

        if ($existing !== null) {
            throw new InvalidArgumentException("Content type '{$definition->type}' already exists");
        }

        $this->registry->register($definition);

        return $definition;
    }

    /**
     * Update an existing content type's schema.
     *
     * @param array<string, mixed> $input
     *
     * @throws InvalidArgumentException If the content type does not exist
     */
    public function updateSchema(string $type, array $input): ContentTypeDefinition
    {
        $existing = $this->registry->get($type);

        if ($existing === null) {
            throw new InvalidArgumentException("Content type '{$type}' not found");
        }

        // Preserve the original type slug
        $input['type'] = $type;
        $definition = $this->buildFromInput($input);

        $this->registry->register($definition);

        return $definition;
    }

    /**
     * Validate and serialize a content type definition to a portable array.
     *
     * @return array{type: string, label: string, icon: string, fields: list<array<string, mixed>>}
     */
    public function serialize(ContentTypeDefinition $definition): array
    {
        return [
            'type' => $definition->type,
            'label' => $definition->label,
            'icon' => $definition->icon,
            'fields' => array_map(static fn(ContentTypeField $f): array => [
                'key' => $f->fieldKey,
                'type' => $f->fieldType->value,
                'required' => $f->required,
                'translatable' => $f->translatable,
                'searchable' => $f->searchable,
                'filterable' => $f->filterable,
                'sortable' => $f->sortable,
                'validation' => $f->validationRules,
                'default' => $f->defaultValue,
                'sort_order' => $f->sortOrder,
            ], $definition->fields),
        ];
    }

    /**
     * @param array<string, bool> $seenKeys
     * @param array<string, mixed> $fieldData
     */
    private function addFieldFromInput(
        ContentTypeBuilder $builder,
        array $fieldData,
        array &$seenKeys,
    ): void {
        $key = $this->extractString($fieldData, 'key');
        $typeStr = $this->extractString($fieldData, 'type');

        $this->validateFieldKey($key);

        if (array_key_exists($key, $seenKeys)) {
            throw new InvalidArgumentException("Duplicate field key: '{$key}'");
        }

        $seenKeys[$key] = true;

        $fieldType = FieldType::tryFrom($typeStr);

        if ($fieldType === null) {
            $allowed = implode(', ', array_map(
                static fn(FieldType $t): string => $t->value,
                FieldType::cases(),
            ));
            throw new InvalidArgumentException(
                "Invalid field type '{$typeStr}' for field '{$key}'. Allowed: {$allowed}",
            );
        }

        /** @var array<string, mixed> $validation */
        $validation = is_array($fieldData['validation'] ?? null) ? $fieldData['validation'] : [];

        $builder->field(
            key: $key,
            type: $fieldType,
            required: (bool) ($fieldData['required'] ?? false),
            translatable: (bool) ($fieldData['translatable'] ?? false),
            searchable: (bool) ($fieldData['searchable'] ?? false),
            filterable: (bool) ($fieldData['filterable'] ?? false),
            sortable: (bool) ($fieldData['sortable'] ?? false),
            validation: $validation,
            default: $fieldData['default'] ?? null,
        );
    }

    private function validateSlug(string $slug): void
    {
        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            throw new InvalidArgumentException(
                "Invalid content type slug '{$slug}'. Must start with a letter, "
                . 'contain only lowercase letters, numbers, and underscores, '
                . 'and be 3-64 characters.',
            );
        }
    }

    private function validateFieldKey(string $key): void
    {
        $key = strtolower(trim($key));

        if ($key === '') {
            throw new InvalidArgumentException('Field key cannot be empty');
        }

        if (!preg_match('/^[a-z][a-z0-9_]{0,62}[a-z0-9]$|^[a-z]$/', $key)) {
            throw new InvalidArgumentException(
                "Invalid field key '{$key}'. Use lowercase letters, numbers, and underscores.",
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("'{$key}' is required and must be a non-empty string");
        }

        return trim($value);
    }
}
