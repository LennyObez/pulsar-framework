# Content Type API

The CMS provides a content type registry for defining custom content types with typed field definitions. Plugins use this API to extend the CMS with domain-specific content structures, and the admin UI auto-generates forms based on field definitions.

## Built-in Content Types

The CMS ships with two built-in content types defined in the `ContentType` enum:

| Type      | Value     | Description                                               |
| --------- | --------- | --------------------------------------------------------- |
| `Article` | `article` | Blog posts, news articles, editorial content              |
| `Page`    | `page`    | Static pages with hierarchical parent-child relationships |

## `ContentTypeRegistryInterface`

The registry manages all content type definitions, both built-in and custom.

```php
<?php

namespace Pulsar\Extension\Cms\FieldRegistry;

interface ContentTypeRegistryInterface
{
    /**
     * Register a content type definition.
     */
    public function register(ContentTypeDefinition $definition): void;

    /**
     * Retrieve a content type definition by its machine type.
     */
    public function get(string $type): ?ContentTypeDefinition;

    /**
     * Retrieve all registered content type definitions.
     *
     * @return array<string, ContentTypeDefinition> Keyed by type
     */
    public function all(): array;
}
```

### Usage

```php
// Register a custom content type
$registry->register(new ContentTypeDefinition(
    type: 'product-review',
    label: 'Product Review',
    icon: 'star',
    fields: [/* ... */],
));

// Retrieve a specific content type
$definition = $registry->get('product-review');

// List all registered content types
$allTypes = $registry->all();
```

## `ContentTypeDefinition`

Represents a complete content type definition with its associated custom fields.

```php
final readonly class ContentTypeDefinition
{
    /**
     * @param string $type          Machine identifier for the content type
     * @param string $label         Human-readable display name
     * @param string $icon          Icon identifier for admin UI
     * @param list<ContentTypeField> $fields  Ordered list of custom field definitions
     */
    public function __construct(
        public string $type,
        public string $label,
        public string $icon,
        public array $fields,
    ) {}
}
```

| Property | Type   | Description                                                                                                   |
| -------- | ------ | ------------------------------------------------------------------------------------------------------------- |
| `type`   | string | Machine identifier, used in database queries and URL routing. Must be unique across all registered types.     |
| `label`  | string | Human-readable name displayed in the admin panel.                                                             |
| `icon`   | string | Icon identifier for admin UI rendering (e.g., `calendar`, `star`, `file-text`).                               |
| `fields` | list   | Ordered list of `ContentTypeField` definitions. Display order follows the `sortOrder` property on each field. |

## `ContentTypeField`

Defines a structured custom field for a content type. Each field has a type that maps to a specific database value column for proper indexing and type-safe queries.

```php
final readonly class ContentTypeField
{
    public function __construct(
        public string $id,              // UUIDv7
        public string $contentType,     // Which content type this field belongs to
        public string $fieldKey,        // Machine name, unique per content_type
        public FieldType $fieldType,    // Typed field type determining storage column
        public bool $required,          // Whether the field must have a value
        public bool $translatable,      // Whether the value differs per locale
        public bool $searchable,        // Whether to include in search index
        public bool $filterable,        // Whether available as admin list filter
        public bool $sortable,          // Whether available as admin list sort
        public array $validationRules,  // Validation constraints (e.g., min, max, pattern)
        public mixed $defaultValue,     // Default value when none provided
        public int $sortOrder,          // Display order in admin form
    ) {}
}
```

### Properties Reference

| Property          | Type      | Description                                                                                                                                                             |
| ----------------- | --------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`              | string    | UUIDv7 identifier for this field definition.                                                                                                                            |
| `contentType`     | string    | Machine name of the content type this field belongs to.                                                                                                                 |
| `fieldKey`        | string    | Machine name for the field, unique within its content type. Used as the key in custom field value storage.                                                              |
| `fieldType`       | FieldType | The typed field type that determines which database column stores the value.                                                                                            |
| `required`        | bool      | Whether the field must have a non-null value. Enforced during content creation and updates.                                                                             |
| `translatable`    | bool      | Whether the field value can differ per locale. Translatable fields are stored per content translation.                                                                  |
| `searchable`      | bool      | Whether the field value is included in the full-text search index. Searchable field values are concatenated into the `customFieldsText` column on `ContentTranslation`. |
| `filterable`      | bool      | Whether the field appears as a filter option in the admin content list.                                                                                                 |
| `sortable`        | bool      | Whether the field appears as a sort option in the admin content list.                                                                                                   |
| `validationRules` | array     | Key-value validation constraints. See [Validation Rules](#validation-rules).                                                                                            |
| `defaultValue`    | mixed     | Default value used when no explicit value is provided during content creation.                                                                                          |
| `sortOrder`       | int       | Display order in the auto-generated admin form. Lower values appear first.                                                                                              |

## Field Types

The `FieldType` enum defines 14 typed field types. Each maps to a specific database value column for proper indexing:

| Field Type | Value       | Database Column  | Description                                                            |
| ---------- | ----------- | ---------------- | ---------------------------------------------------------------------- |
| `String`   | `string`    | `value_string`   | Plain text, up to database text limit                                  |
| `Int`      | `int`       | `value_int`      | Integer values                                                         |
| `Float`    | `float`     | `value_float`    | Decimal/floating-point values                                          |
| `Bool`     | `bool`      | `value_bool`     | Boolean true/false                                                     |
| `Date`     | `date`      | `value_datetime` | Date without time component                                            |
| `DateTime` | `datetime`  | `value_datetime` | Date with time component                                               |
| `Enum`     | `enum`      | `value_string`   | Predefined set of string values                                        |
| `Relation` | `relation`  | `value_json`     | Reference to another content item (stored as JSON with ID and type)    |
| `Json`     | `json`      | `value_json`     | Arbitrary JSON data                                                    |
| `Media`    | `media`     | `value_json`     | Reference to a media asset (stored as JSON with asset ID and metadata) |
| `RichText` | `rich_text` | `value_json`     | Rich text content (stored as structured JSON)                          |
| `Color`    | `color`     | `value_string`   | CSS color value (hex, rgb, hsl)                                        |
| `Url`      | `url`       | `value_string`   | URL string                                                             |
| `Email`    | `email`     | `value_string`   | Email address string                                                   |

### Storage Strategy

Only the matching column is populated for each field value; all other columns remain `NULL`. This enables type-safe indexing and querying:

```
FieldType::Int    --> value_int = 42,  value_string = NULL, value_json = NULL, ...
FieldType::String --> value_string = "hello", value_int = NULL, value_json = NULL, ...
FieldType::Json   --> value_json = '{"key":"val"}', value_string = NULL, value_int = NULL, ...
```

## Validation Rules

The `validationRules` array on `ContentTypeField` accepts key-value pairs for field-level validation. The available rules depend on the field type:

### String/Url/Email Fields

| Rule         | Type   | Description                                     |
| ------------ | ------ | ----------------------------------------------- |
| `min_length` | int    | Minimum string length                           |
| `max_length` | int    | Maximum string length                           |
| `pattern`    | string | Regular expression pattern the value must match |

### Int/Float Fields

| Rule  | Type      | Description           |
| ----- | --------- | --------------------- |
| `min` | int/float | Minimum allowed value |
| `max` | int/float | Maximum allowed value |

### Enum Fields

| Rule      | Type | Description                   |
| --------- | ---- | ----------------------------- |
| `options` | list | List of allowed string values |

### Date/DateTime Fields

| Rule       | Type   | Description                      |
| ---------- | ------ | -------------------------------- |
| `min_date` | string | Earliest allowed date (ISO 8601) |
| `max_date` | string | Latest allowed date (ISO 8601)   |

### Media Fields

| Rule            | Type | Description                                              |
| --------------- | ---- | -------------------------------------------------------- |
| `allowed_types` | list | Allowed MIME types (e.g., `["image/jpeg", "image/png"]`) |
| `max_size`      | int  | Maximum file size in bytes                               |

## Defining Custom Content Types

### From a Plugin

Plugins register content types in their `register()` method via `CmsPluginContext`:

```php
public function register(CmsPluginContext $context): void
{
    $context->registerContentType(new ContentTypeDefinition(
        type: 'recipe',
        label: 'Recipe',
        icon: 'utensils',
        fields: [
            new ContentTypeField(
                id: 'field-recipe-prep-time',
                contentType: 'recipe',
                fieldKey: 'prep_time_minutes',
                fieldType: FieldType::Int,
                required: true,
                translatable: false,
                searchable: false,
                filterable: true,
                sortable: true,
                validationRules: ['min' => 1, 'max' => 1440],
                defaultValue: 30,
                sortOrder: 1,
            ),
            new ContentTypeField(
                id: 'field-recipe-servings',
                contentType: 'recipe',
                fieldKey: 'servings',
                fieldType: FieldType::Int,
                required: true,
                translatable: false,
                searchable: false,
                filterable: true,
                sortable: true,
                validationRules: ['min' => 1, 'max' => 100],
                defaultValue: 4,
                sortOrder: 2,
            ),
            new ContentTypeField(
                id: 'field-recipe-difficulty',
                contentType: 'recipe',
                fieldKey: 'difficulty',
                fieldType: FieldType::Enum,
                required: true,
                translatable: false,
                searchable: true,
                filterable: true,
                sortable: true,
                validationRules: ['options' => ['easy', 'medium', 'hard', 'expert']],
                defaultValue: 'medium',
                sortOrder: 3,
            ),
            new ContentTypeField(
                id: 'field-recipe-ingredients',
                contentType: 'recipe',
                fieldKey: 'ingredients',
                fieldType: FieldType::Json,
                required: true,
                translatable: true,
                searchable: true,
                filterable: false,
                sortable: false,
                validationRules: [],
                defaultValue: [],
                sortOrder: 4,
            ),
            new ContentTypeField(
                id: 'field-recipe-hero-image',
                contentType: 'recipe',
                fieldKey: 'hero_image',
                fieldType: FieldType::Media,
                required: false,
                translatable: false,
                searchable: false,
                filterable: false,
                sortable: false,
                validationRules: [
                    'allowed_types' => ['image/jpeg', 'image/png', 'image/webp'],
                ],
                defaultValue: null,
                sortOrder: 5,
            ),
        ],
    ));
}
```

### Admin Form Auto-Generation

When a content type is registered, the admin UI automatically generates an edit form based on the field definitions:

- Fields are ordered by `sortOrder` (ascending)
- `required` fields show a required indicator and prevent form submission without a value
- `filterable` fields appear in the admin list filter sidebar
- `sortable` fields appear in the admin list sort dropdown
- Field type determines the form input widget (text input, number input, select dropdown, date picker, media picker, rich text editor, color picker, etc.)

## Querying by Custom Field Values

Custom field values are stored in typed columns, enabling efficient database queries:

```php
// The FieldRegistryRepositoryInterface provides field value storage and retrieval
$repository = $container->get(FieldRegistryRepositoryInterface::class);
```

Fields with `searchable: true` have their values concatenated into the `customFieldsText` column on `ContentTranslation`, which feeds into the full-text search index. This enables searching across custom field values without separate queries.

Fields with `filterable: true` can be queried directly via their typed database column for the admin list filter UI.

## Related Documentation

- [Plugin Development Guide](plugin-development.md) -- How plugins register content types
- [Hook Reference](hook-reference.md) -- Content lifecycle hooks for custom types
- [Architecture Overview](architecture.md) -- FieldRegistry module boundaries
