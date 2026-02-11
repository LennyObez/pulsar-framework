# Schema-Driven Code Generation

Pulsar's codegen engine produces complete, production-quality PHP from a single entity definition. You define the schema once; the engine generates repositories, migrations, validation rules, forms, API resources, admin views, policies, and test factories.

## Two Generation Paths

### Domain-first (recommended)

Start with an `EntityDefinition` that describes your domain model. The engine generates all code from that definition, including the database migration.

```
EntityDefinition  -->  8 generators  -->  GeneratedFileSet
                                          ├── Contracts/OrderRepositoryInterface.php
                                          ├── Repository/OrderRepository.php
                                          ├── database/migrations/20260303120000_orders.php
                                          ├── Validation/OrderRules.php
                                          ├── Form/OrderForm.php
                                          ├── Api/OrderResource.php
                                          ├── Admin/OrderAdminResource.php
                                          ├── Policy/OrderPolicy.php
                                          └── Factory/OrderFactory.php
```

This path gives you full control over the entity schema and is the default workflow for new projects.

### Legacy-first

Start with an existing database. Pulsar introspects column metadata and builds an `EntityDefinition` from it, then runs the same generators.

```
Database table  -->  ColumnInfo[]  -->  EntityDefinition  -->  generators
```

`EntityDefinition::fromDatabaseColumns()` handles the conversion. It detects timestamp columns (`created_at`, `updated_at`), soft-delete columns (`deleted_at`), and audit columns (`created_by`, `updated_by`) by convention. PHP identifiers are derived through `IdentifierNormalizer`, which handles reserved words and casing.

## Commands Reference

### `pulsar make:from-schema`

Generate all artifacts from an entity schema file.

```bash
pulsar make:from-schema schema/order.json
pulsar make:from-schema schema/order.json --force     # Overwrite existing files
pulsar make:from-schema schema/order.json --namespace App\\Domain
```

**Arguments:**

| Argument      | Description                                        |
| ------------- | -------------------------------------------------- |
| `schema`      | Path to the JSON schema file                       |
| `--force`     | Overwrite existing files (default: fail on exists) |
| `--namespace` | PHP namespace prefix (default: `App`)              |
| `--output`    | Output base directory (default: project root)      |

The schema file is a JSON representation of `EntityDefinition`:

```json
{
  "className": "Order",
  "namespace": "App\\Entity",
  "tableName": "orders",
  "primaryKey": "id",
  "hasTimestamps": true,
  "hasSoftDeletes": false,
  "isAuditAware": true,
  "properties": [
    {
      "name": "id",
      "phpType": "int",
      "columnName": "id",
      "columnType": "integer",
      "nullable": false,
      "hasDefault": true,
      "defaultValue": null,
      "validationRules": [],
      "isFilterable": true,
      "isSortable": true,
      "length": null,
      "isPrimaryKey": true
    },
    {
      "name": "customerEmail",
      "phpType": "string",
      "columnName": "customer_email",
      "columnType": "varchar(255)",
      "nullable": false,
      "hasDefault": false,
      "defaultValue": null,
      "validationRules": ["required", "email", "max:255"],
      "isFilterable": true,
      "isSortable": true,
      "length": 255,
      "isPrimaryKey": false
    },
    {
      "name": "totalCents",
      "phpType": "int",
      "columnName": "total_cents",
      "columnType": "integer",
      "nullable": false,
      "hasDefault": false,
      "defaultValue": null,
      "validationRules": ["required", "integer"],
      "isFilterable": true,
      "isSortable": true,
      "length": null,
      "isPrimaryKey": false
    }
  ],
  "relationships": []
}
```

### `pulsar make:migration-diff`

Compare the current schema snapshot against the live database and generate a migration for the differences.

```bash
pulsar make:migration-diff
pulsar make:migration-diff --name add_status_to_orders
```

**Arguments:**

| Argument | Description                                            |
| -------- | ------------------------------------------------------ |
| `--name` | Migration filename suffix (default: entity table name) |

The engine stores a deterministic schema snapshot at `database/.schema-snapshot.json`. Each time you run `make:migration-diff`, it compares the current entity definitions against the stored snapshot using `SchemaDiff`, generates migration operations for the differences, and updates the snapshot file.

The snapshot uses SHA-256 hashing for quick comparison. Two equivalent schemas always produce the same hash regardless of property ordering.

### `pulsar make:crud`

Generate a complete CRUD stack for an entity in a single command.

```bash
pulsar make:crud Order
pulsar make:crud Order --force --namespace App\\Domain
```

This is a convenience wrapper that runs all 8 generators for the named entity. Equivalent to running `make:from-schema` with a schema file, but builds the `EntityDefinition` from an existing entity mapping or database table.

## Generated Artifacts

### 1. Repository (interface + implementation)

**Generator:** `RepositoryGenerator`
**Output:** `Contracts/{Class}RepositoryInterface.php` + `Repository/{Class}Repository.php`

The interface provides standard CRUD methods (`find`, `findAll`, `create`, `update`, `delete`). The implementation uses `ConnectionInterface` with parameterized query bindings throughout. SQL injection is impossible by construction -- every value goes through named parameter binding.

Custom finder methods are generated for filterable, non-primary-key, string/int-typed columns. For example, a filterable `customer_email` column produces `findByCustomerEmail(string $value): array`.

```php
// Generated interface
interface OrderRepositoryInterface
{
    public function find(int $id): ?array;
    public function findAll(): array;
    public function create(array $data): int|string;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function findByCustomerEmail(string $value): array;
}

// Generated implementation (all queries use parameterized bindings)
$result = $this->connection->query(
    'SELECT * FROM orders WHERE customer_email = :value',
    ['value' => $value],
);
```

### 2. Migration

**Generator:** `MigrationGenerator`
**Output:** `database/migrations/{YYYYMMDDHHMMSS}_description.php`

Produces anonymous-class migrations implementing `MigrationInterface` with `up()` and `down()` methods. The `down()` method reverses each operation: `CreateTable` becomes `DROP TABLE`, `AddColumn` becomes `DROP COLUMN`, `ModifyColumn` reverts to the previous type.

Supported operations: `CreateTable`, `DropTable`, `AddColumn`, `DropColumn`, `ModifyColumn`, `AddIndex`, `DropIndex`, `AddForeignKey`, `DropForeignKey`.

The diff engine (`SchemaDiff`) produces operations in dependency order: creates before adds, drops after removes. Foreign keys for new tables are added after the table exists. Foreign keys on dropped tables are removed before the table drop.

Column types are mapped to standard SQL DDL types:

| Schema type                      | SQL DDL type       |
| -------------------------------- | ------------------ |
| `int`, `integer`                 | `INTEGER`          |
| `bigint`                         | `BIGINT`           |
| `bool`, `boolean`                | `BOOLEAN`          |
| `float`, `double`, `real`        | `DOUBLE PRECISION` |
| `text`, `mediumtext`, `longtext` | `TEXT`             |
| `json`, `jsonb`                  | `JSON`             |
| `datetime`, `timestamp`          | `TIMESTAMP`        |
| `uuid`                           | `UUID`             |

### 3. Validation Rules

**Generator:** `ValidationRuleGenerator`
**Output:** `Validation/{Class}Rules.php`

Generates a rules class with a static `rules()` method returning a field-to-rules map. Rules are inferred from property metadata:

- Non-nullable columns get `required`
- String columns get `string` + `max:{length}` when a length is specified
- Integer columns get `integer`
- Float columns get `numeric`
- Boolean columns get `boolean`
- DateTime columns get `date`
- Array columns get `array`

Columns with explicit `validationRules` in the property definition take precedence over inferred rules. Primary key columns are excluded.

```php
final readonly class OrderRules
{
    public static function rules(): array
    {
        return [
            'customerEmail' => ['required', 'email', 'max:255'],
            'totalCents' => ['required', 'integer'],
        ];
    }
}
```

### 4. Form

**Generator:** `FormGenerator`
**Output:** `Form/{Class}Form.php`

Generates a form class with CSRF protection included by default. Fields are mapped from PHP types to form input types:

| PHP type               | Form type  |
| ---------------------- | ---------- |
| `string`               | `text`     |
| `string` (text column) | `textarea` |
| `int`, `float`         | `number`   |
| `bool`                 | `checkbox` |
| `\DateTimeImmutable`   | `datetime` |
| `array`                | `textarea` |

The `required` flag is derived from the column's nullable status. Primary key columns are excluded from form fields.

```php
final readonly class OrderForm
{
    public static function fields(): array
    {
        return [
            '_csrf' => ['type' => 'csrf'],
            'customerEmail' => ['type' => 'text', 'required' => true],
            'totalCents' => ['type' => 'number', 'required' => true],
        ];
    }
}
```

### 5. API Resource

**Generator:** `ApiResourceGenerator`
**Output:** `Api/{Class}Resource.php`

Uses **deny-by-default field exposure**. Only explicitly listed fields are visible in API responses. Audit columns (`created_by`, `updated_by`, `deleted_at`) are hidden by default. Primary key columns are always exposed.

Each field declares three flags: `expose` (visible in response), `filterable` (queryable via API), and `sortable` (orderable via API). These come from the property definition's `isFilterable` and `isSortable` metadata.

```php
final readonly class OrderResource
{
    public static function fields(): array
    {
        return [
            'id' => ['expose' => true, 'filterable' => true, 'sortable' => true],
            'customerEmail' => ['expose' => true, 'filterable' => true, 'sortable' => true],
            'totalCents' => ['expose' => true, 'filterable' => true, 'sortable' => true],
            'createdBy' => ['expose' => false, 'filterable' => true, 'sortable' => true],
        ];
    }
}
```

### 6. Admin Resource

**Generator:** `AdminResourceGenerator`
**Output:** `Admin/{Class}AdminResource.php`

Generates CRUD view configuration for the admin panel with three views:

- **List view** (`listColumns`): All columns including primary key
- **Form fields** (`formFields`): All columns except primary key (for create/edit)
- **Show view** (`showFields`): All columns including primary key (for detail view)

### 7. Authorization Policy

**Generator:** `PolicyGenerator`
**Output:** `Policy/{Class}Policy.php`

Generates **DENY-BY-DEFAULT** RBAC policies. All five operations (`viewAny`, `view`, `create`, `update`, `delete`) return `false` until the developer explicitly grants access. This ensures no entity is accidentally exposed without an authorization decision.

```php
final readonly class OrderPolicy
{
    public function viewAny(): bool { return false; }
    public function view(): bool { return false; }
    public function create(): bool { return false; }
    public function update(): bool { return false; }
    public function delete(): bool { return false; }
}
```

### 8. Test Factory

**Generator:** `TestFactoryGenerator`
**Output:** `Factory/{Class}Factory.php`

Generates a factory class with type-sensible default values for each property:

| PHP type             | Default value                          |
| -------------------- | -------------------------------------- |
| `string`             | `'{propertyName}_value'`               |
| `int`                | `0`                                    |
| `float`              | `0.0`                                  |
| `bool`               | `false`                                |
| `array`              | `[]`                                   |
| `\DateTimeImmutable` | `new \DateTimeImmutable('2024-01-01')` |
| nullable             | `null`                                 |

Primary key columns are excluded. Nullable columns default to `null` regardless of their type.

## Security Model

The codegen engine enforces multiple security boundaries by design.

### Safe template rendering

The `TemplateRenderer` uses `str_replace()` for `{{variable}}` placeholders. There is no `eval()`, no `include()`, no arbitrary code execution. Templates support four case-transformation filters (`PascalCase`, `camelCase`, `snake_case`, `kebab-case`) through deterministic string manipulation. Unknown filters throw `InvalidArgumentException`.

### Path validation

`PathValidator` enforces a directory allowlist. By default, generated files may only be written to `src/`, `tests/`, `config/`, or `database/migrations/` relative to the project root. Directory traversal (`..`) is rejected. Paths outside the allowlist throw `InvalidArgumentException`. The allowlist is configurable per-project.

### Non-destructive generation

The `OverwritePolicy` enum controls file conflict behavior:

| Policy  | Behavior                                   |
| ------- | ------------------------------------------ |
| `Skip`  | Skip generation if the file already exists |
| `Force` | Overwrite the file regardless              |
| `Fail`  | Throw an exception if the file exists      |

The default policy is `Fail`. Passing `--force` to CLI commands switches to `Force`. `GeneratedFileSet` detects conflicts before any file is written, ensuring the operation is atomic: either all files are generated or none are.

### Parameterized queries

`RepositoryGenerator` produces all queries with named parameter bindings (`:param`). Column names and table names come from the entity definition at generation time, not from user input at runtime. SQL injection is structurally impossible in generated repository code.

### Deny-by-default authorization

`PolicyGenerator` produces policies where every operation returns `false`. No entity is accessible until the developer explicitly opens specific operations. This prevents accidental data exposure in regulated environments.

### Deny-by-default field exposure

`ApiResourceGenerator` hides audit columns (`created_by`, `updated_by`, `deleted_at`) from API responses by default. Fields must be explicitly marked for exposure.

### Identifier normalization

`IdentifierNormalizer` prevents collisions with PHP and SQL reserved words. If a database table or column name matches a reserved word (`class`, `select`, `return`, etc.), the generated identifier is automatically suffixed: class names get `Entity` appended, property names get `Value` appended. Names starting with non-alphabetic characters are prefixed to ensure valid PHP identifiers.

## Customization

### Generator configuration

All generators accept a `GeneratorConfig` with three settings:

```php
new GeneratorConfig(
    outputBaseDirectory: '/path/to/project',
    namespacePrefix: 'App',        // PHP namespace prefix
    force: false,                   // OverwritePolicy: Force vs Fail
);
```

### Custom generators

Implement `GeneratorInterface` or extend `AbstractGenerator`:

```php
final class CustomGenerator extends AbstractGenerator
{
    protected function doGenerate(EntityDefinition $entity, GeneratorConfig $config): array
    {
        // Build template variables from entity definition
        $variables = [
            new TemplateVariable('className', $entity->className),
        ];

        return [
            new GeneratedFile(
                targetPath: $config->outputBaseDirectory . '/Custom/' . $entity->className . '.php',
                content: $this->renderer->render($template, $variables),
                overwritePolicy: OverwritePolicy::Fail,
            ),
        ];
    }
}
```

`AbstractGenerator` provides path validation and conflict detection automatically. Your `doGenerate()` method only needs to return a list of `GeneratedFile` instances.

### Template filters

The `TemplateRenderer` supports case transformations on any variable:

```
{{className}}             -->  OrderLineItem
{{className|snake_case}}  -->  order_line_item
{{className|camelCase}}   -->  orderLineItem
{{className|kebab-case}}  -->  order-line-item
{{className|PascalCase}}  -->  OrderLineItem
```

### Schema snapshots

The `SchemaSnapshotStore` persists schema state at `database/.schema-snapshot.json`. Each snapshot records every entity definition in a deterministic, sorted JSON format. The `SchemaDiff` engine compares two snapshots to produce a `DiffResult` containing ordered `SchemaOperation` objects.

You can build custom tooling on top of the snapshot/diff pipeline:

```php
$store = new SchemaSnapshotStore('database/.schema-snapshot.json');
$old = $store->load();
$new = new SchemaSnapshot($entities, version: '2');

$diff = new SchemaDiff();
$result = $diff->diff($old, $new);

foreach ($result->operations as $op) {
    // SchemaOperation with type, table, column, metadata
}
```

### Path allowlist

Configure the path validator to permit additional output directories:

```php
new PathValidator(
    projectRoot: '/path/to/project',
    allowedDirectories: ['src/', 'tests/', 'config/', 'database/migrations/', 'custom/'],
);
```

## Related Documentation

- [Control Packs](control-packs.md) -- domain-specific starter kits with compliance scaffolding
- [ADR-0028: Codegen Engine & Control Packs](adr/0028-codegen-and-control-packs.md) -- architecture decisions
