# Studio Admin Panel

ORM-agnostic CRUD admin panel built as a Pulsar extension. Provides resource management, bulk actions, export with evidence hashing, global search, dashboard widgets, and saved views. Disabled by default.

## Overview

```
AdminExtension
├── DataResourceInterface (resource contract)
├── AdminAccessGate (ABAC policy)
├── AdminResourcePolicy (role + permission checks)
├── FieldVisibilityFilter (redaction + visibility)
├── OrmResourceAdapter (ORM bridge)
├── Middleware stack (auth, access, CSRF, CSP, rate limit, audit)
├── Feature handlers (list, view, create, update, delete, bulk, export, search)
├── Widget system (dashboard)
├── Saved views (filter/sort presets)
└── Storage adapters (SQLite, DB-backed)
```

The admin panel is ORM-agnostic at its core. The `DataResourceInterface` contract defines what the admin needs from a data source, and the `OrmResourceAdapter` bridges ORM entities to this contract. Custom data sources can implement `DataResourceInterface` directly.

## Configuration

```php
// config/admin.php
return [
    'enabled' => false,     // Must be explicitly enabled

    'route_prefix' => '/admin',

    'security' => [
        'required_role' => 'admin',
        'require_2fa'   => true,
        'csrf_rotation' => true,
        'csp_nonce'     => true,
    ],

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page'     => 100,
    ],

    'rate_limit' => [
        'read_limit'     => 120,    // Reads per window
        'write_limit'    => 30,     // Writes per window
        'export_limit'   => 5,      // Exports per window
        'window_seconds' => 60,
    ],

    'storage' => [
        'driver'      => 'sqlite',  // 'sqlite' or 'db'
        'sqlite_path' => null,      // Defaults to storage/admin/admin.sqlite
    ],
];
```

The admin panel is disabled by default. Set `enabled` to `true` and ensure the security requirements (role, 2FA) are satisfied before deploying to any environment.

## Authentication

### Session-Based Authentication

The admin panel uses session-based authentication via `SessionGuard`. Every request to admin routes passes through `AdminAuthMiddleware`, which verifies the session is active and the authenticated user holds the required role.

### MFA Requirement

When `security.require_2fa` is `true` (the default), the admin middleware rejects users who have not completed multi-factor authentication. This is enforced at the middleware level — no admin routes are accessible without MFA.

### Step-Up Re-authentication

For sensitive operations (bulk deletes, exports, settings changes), the admin panel can trigger step-up re-authentication. The user must re-confirm their identity before the operation proceeds.

## Security

### Environment Gating

The admin panel should be gated by environment. In production, combine with a reverse proxy allowlist or VPN requirement:

```php
'enabled' => env('APP_ENV') !== 'production' || env('ADMIN_ENABLED') === 'true',
```

### CIDR Allowlist

Configure allowed IP ranges at the infrastructure level (reverse proxy, load balancer). The admin middleware does not implement IP filtering directly — this is intentionally delegated to the network layer for defense in depth.

### Content Security Policy (CSP)

`AdminCspMiddleware` injects a strict Content Security Policy header on every admin response. When `csp_nonce` is enabled, each response includes a unique nonce for inline scripts and styles.

### CSRF Token Lifecycle

`AdminCsrfMiddleware` enforces CSRF protection on all mutating requests (POST, PUT, PATCH, DELETE). When `csrf_rotation` is enabled, the CSRF token is rotated after every successful mutation to prevent token reuse.

### Error Hygiene

`AdminSafetyMode` sanitizes error responses in production. Internal details are stripped:

| Stripped in production |
| ---------------------- |
| `trace`                |
| `sql`                  |
| `bindings`             |
| `file`                 |
| `line`                 |
| `class`                |
| `function`             |

In debug mode (`APP_DEBUG=true`), full error details are included for development convenience.

### Rate Limiting

`AdminRateLimitMiddleware` enforces per-window rate limits:

| Operation | Default Limit | Window |
| --------- | ------------- | ------ |
| Read      | 120/min       | 60s    |
| Write     | 30/min        | 60s    |
| Export    | 5/min         | 60s    |

## Resource Registration

### DataResourceInterface

Every admin resource implements `DataResourceInterface`:

```php
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\BulkAction;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Domain\ValidationRule;

class UserResource implements DataResourceInterface
{
    public function name(): string { return 'users'; }
    public function label(): string { return 'User'; }
    public function pluralLabel(): string { return 'Users'; }
    public function icon(): string { return 'users'; }
    public function primaryKey(): string { return 'id'; }
    public function defaultSortField(): string { return 'id'; }
    public function defaultSortDirection(): string { return 'desc'; }
    public function auditReads(): bool { return false; }

    public function fields(): array
    {
        return [
            new FieldDefinition(
                name: 'id',
                type: FieldType::Integer,
                label: 'ID',
                sortable: true,
                editable: false,
            ),
            new FieldDefinition(
                name: 'name',
                type: FieldType::String,
                label: 'Name',
                sortable: true,
                filterable: true,
                searchable: true,
                rules: [
                    new ValidationRule('required'),
                    new ValidationRule('max_length', parameter: 255),
                ],
            ),
            new FieldDefinition(
                name: 'email',
                type: FieldType::Email,
                label: 'Email',
                sortable: true,
                searchable: true,
                rules: [
                    new ValidationRule('required'),
                    new ValidationRule('email'),
                ],
            ),
            new FieldDefinition(
                name: 'password',
                type: FieldType::String,
                label: 'Password',
                redacted: true,
                visibleOnList: false,
                exportable: false,
            ),
        ];
    }

    public function operations(): array
    {
        return [
            ResourceOperation::List,
            ResourceOperation::View,
            ResourceOperation::Create,
            ResourceOperation::Update,
            ResourceOperation::Delete,
            ResourceOperation::Export,
        ];
    }

    public function bulkActions(): array
    {
        return [
            new BulkAction(
                name: 'deactivate',
                label: 'Deactivate',
                destructive: true,
                requireConfirmation: true,
            ),
        ];
    }

    public function exportableFields(): array
    {
        return ['id', 'name', 'email', 'created_at'];
    }
}
```

### ORM Resource Adapter

For ORM entities, use the `OrmResourceAdapter` to bridge entity metadata to the admin interface without implementing `DataResourceInterface` from scratch:

```php
use Pulsar\Extension\Admin\Internal\Adapter\OrmResourceAdapter;

$resource = new OrmResourceAdapter(
    resourceName: 'users',
    resourceLabel: 'User',
    resourcePluralLabel: 'Users',
    resourceIcon: 'users',
    tableName: 'users',
    fields: [/* FieldDefinition array */],
    operations: [ResourceOperation::List, ResourceOperation::View, ...],
    bulkActions: [],
    exportableFields: ['id', 'name', 'email'],
    auditReadsEnabled: false,
    pk: 'id',
    sortField: 'id',
    sortDirection: 'desc',
);
```

### Audit Reads per Resource

Set `auditReads()` to `true` on resources that contain sensitive data (e.g., patient records, financial data). When enabled, every list and view operation is logged to the audit trail.

## Field Definitions

Fields control how data appears in the admin interface.

### FieldDefinition Properties

| Property           | Type                   | Default | Description                       |
| ------------------ | ---------------------- | ------- | --------------------------------- |
| `name`             | `string`               | —       | Database column / property name   |
| `type`             | `FieldType`            | —       | Display and validation type       |
| `label`            | `string`               | —       | Human-readable label              |
| `sortable`         | `bool`                 | `false` | Show sort controls on list view   |
| `filterable`       | `bool`                 | `false` | Show filter controls on list view |
| `searchable`       | `bool`                 | `false` | Include in global search          |
| `redacted`         | `bool`                 | `false` | Replace value with `••••••`       |
| `exportable`       | `bool`                 | `true`  | Include in exports                |
| `editable`         | `bool`                 | `true`  | Show in create/edit forms         |
| `visibleOnList`    | `bool`                 | `true`  | Show in list view                 |
| `visibleOnDetail`  | `bool`                 | `true`  | Show in detail view               |
| `visibleOnForm`    | `bool`                 | `true`  | Show in create/edit forms         |
| `rules`            | `list<ValidationRule>` | `[]`    | Validation rules                  |
| `enumValues`       | `list<string>`         | `[]`    | Allowed values (for Enum fields)  |
| `relationResource` | `?string`              | `null`  | Related admin resource name       |
| `placeholder`      | `?string`              | `null`  | Form field placeholder            |
| `helpText`         | `?string`              | `null`  | Form field help text              |

### Field Types

| `FieldType` | Description             |
| ----------- | ----------------------- |
| `String`    | Text input              |
| `Text`      | Textarea                |
| `Integer`   | Number input            |
| `Float`     | Decimal number input    |
| `Boolean`   | Checkbox / toggle       |
| `Date`      | Date picker             |
| `DateTime`  | Date and time picker    |
| `Email`     | Email input             |
| `Url`       | URL input               |
| `Json`      | JSON editor             |
| `Enum`      | Select dropdown         |
| `Relation`  | Related resource picker |

### Validation Rules

| Rule         | Parameter | Description                     |
| ------------ | --------- | ------------------------------- |
| `required`   | —         | Value must not be null or empty |
| `min_length` | `int`     | Minimum string length           |
| `max_length` | `int`     | Maximum string length           |
| `min`        | `number`  | Minimum numeric value           |
| `max`        | `number`  | Maximum numeric value           |
| `pattern`    | `string`  | Regex pattern                   |
| `email`      | —         | Must be a valid email address   |
| `url`        | —         | Must be a valid URL             |

## Policy System

### Permission Model

The admin panel uses an ABAC (Attribute-Based Access Control) policy model. Permissions follow the pattern `admin.{resource}.{operation}`.

### Built-in Permissions

| Permission               | Scope                  | Required Role |
| ------------------------ | ---------------------- | ------------- |
| `admin.access`           | Access the admin panel | `admin`       |
| `admin.dashboard`        | View the dashboard     | `admin`       |
| `admin.resources.manage` | CRUD on resources      | `admin`       |
| `admin.export`           | Export resource data   | `admin`       |
| `admin.audit.view`       | View audit logs        | `admin`       |
| `admin.settings`         | Manage admin settings  | `super_admin` |

### Field Visibility and Redaction

`FieldVisibilityFilter` controls what data is visible in each context:

- **List view**: Only fields with `visibleOnList = true` are shown. Redacted fields display `••••••`.
- **Detail view**: Only fields with `visibleOnDetail = true` are shown. Redacted fields display `••••••`.
- **Form view**: Only fields with `visibleOnForm = true` and `editable = true` are shown.
- **Export**: Only fields in the `exportableFields()` allowlist are included. Redacted fields are excluded entirely (not replaced with placeholder).

The primary key is always included in filtered output regardless of visibility settings.

## CRUD Operations

### List

Paginated listing with sort, filter, and search. Respects field visibility and redaction rules.

### View

Single record detail view. If `auditReads()` is true on the resource, an audit entry is written.

### Create

Form-based record creation. Fields are validated against the resource's `ValidationRule` definitions. An audit entry is written on success via `MutationContext`.

### Update

Form-based record update. Same validation and audit behavior as create.

### Delete

Record deletion (or soft-delete if the underlying data source supports it). Audit entry written on success.

### Audit Trail

Every mutating operation (create, update, delete, bulk action) records an `ActionHistoryEntry`:

```php
ActionHistoryEntry(
    id: '...',                    // Random hex ID
    action: 'create',            // Operation name
    resourceName: 'users',       // Resource name
    recordId: '42',              // Affected record ID
    actor: 'user:alice',         // From MutationContext
    timestamp: 1700000000,       // Unix timestamp
    success: true,               // Outcome
    detail: null,                // Optional detail message
)
```

## Bulk Actions

Bulk actions operate on multiple records in a single transactional operation.

### Defining Bulk Actions

```php
public function bulkActions(): array
{
    return [
        new BulkAction(
            name: 'activate',
            label: 'Activate',
            destructive: false,
        ),
        new BulkAction(
            name: 'delete',
            label: 'Delete Selected',
            destructive: true,
            requireConfirmation: true,
            icon: 'trash',
        ),
    ];
}
```

### Execution Rules

- The resource must include `ResourceOperation::BulkAction` in its `operations()`.
- Each bulk action is authorized against `admin.resources.manage`.
- Destructive actions require user confirmation in the UI when `requireConfirmation` is `true`.
- Execution is transactional — either all records are affected or none.
- An audit entry is written for the batch with `action: "bulk.{actionName}"`.

## Global Search

Global search queries all registered resources that have searchable fields.

```php
// Internally:
foreach ($registry->all() as $resource) {
    $matches = $query->search($resource, $searchTerm, $limitPerResource);
}
```

Results are filtered through `FieldVisibilityFilter` before being returned. Only fields marked `searchable: true` are queried. Results are grouped by resource name.

## Saved Views

Saved views persist filter, sort, and pagination presets for resource list views.

```php
SavedView(
    id: 'view-abc',
    resourceName: 'users',
    label: 'Active Admins',
    filters: ['active' => true, 'role' => 'admin'],
    sort: ['name' => 'ASC'],
    perPage: 50,
    createdBy: 'user:alice',
    isDefault: false,
    createdAt: 1700000000,
)
```

### Features

- Create, update, delete saved views
- Mark a view as default for a resource
- Views are per-user (`createdBy` tracks ownership)
- Stored via `SavedViewStoreInterface` (SQLite or DB-backed)

## Dashboard Widgets

The admin dashboard supports pluggable widgets via `WidgetInterface`.

### Widget Contract

```php
use Pulsar\Extension\Admin\Contracts\WidgetInterface;

class ActiveUsersWidget implements WidgetInterface
{
    public function id(): string { return 'active-users'; }
    public function label(): string { return 'Active Users'; }
    public function size(): string { return 'small'; } // small, medium, large

    public function render(): array
    {
        return [
            'count' => 42,
            'trend' => '+5%',
        ];
    }
}
```

### Built-in Widgets

| Widget                 | Description                             |
| ---------------------- | --------------------------------------- |
| `ResourceCountWidget`  | Shows total record count for a resource |
| `RecentActivityWidget` | Shows recent action history entries     |

## Export

### Formats

| Format | MIME Type          | Extension |
| ------ | ------------------ | --------- |
| CSV    | `text/csv`         | `.csv`    |
| JSON   | `application/json` | `.json`   |

### Column Allowlist

Only fields listed in `exportableFields()` are included in exports. Redacted fields are excluded entirely (never exported, even as placeholders).

### Evidence Hash

Every export is hashed using SHA-256 via `HashingStreamWrapper`. The evidence hash is:

- Computed over the full export output
- Included in the audit log entry
- Returned in the `ExportResourceResult` for verification

This allows independent verification that an export file has not been modified after generation.

### Scalar-Only Output

Export values are coerced to scalar types (`string`, `int`, `float`, `bool`, `null`). Complex values (arrays, objects) are JSON-encoded to a string. This ensures portability and prevents injection in CSV output.

### Audit Trail

Every export operation writes an audit entry with:

- Format (CSV/JSON)
- Row count
- Evidence hash (SHA-256)
- Filename

## Storage

### Adapters

| Driver   | Class                                               | Use Case                                  |
| -------- | --------------------------------------------------- | ----------------------------------------- |
| `sqlite` | `SqliteSavedViewStore` / `SqliteActionHistoryStore` | Default; zero-config, local file          |
| `db`     | `DbSavedViewStore` / `DbActionHistoryStore`         | Production; uses the application database |

### Production Constraints

For production deployments:

- Prefer the `db` storage driver to share state across application instances.
- The `sqlite` driver stores data in a local file and is not suitable for horizontally scaled deployments.
- Action history grows over time — implement a retention policy or archival job for long-running applications.

## Audit Log Integrity

The admin panel writes audit entries for all operations through Pulsar's `AuditLogger`. These entries participate in the framework's tamper-evident audit chain (HMAC-BLAKE2b).

Audited operations:

| Operation     | Audit Action                                        |
| ------------- | --------------------------------------------------- |
| View resource | `admin.view.{resource}` (if `auditReads()` enabled) |
| Create record | `admin.create.{resource}`                           |
| Update record | `admin.update.{resource}`                           |
| Delete record | `admin.delete.{resource}`                           |
| Bulk action   | `admin.bulk.{action}`                               |
| Export        | `admin.export.{resource}`                           |

Each audit entry includes the actor, resource, timestamp, and outcome.
