# API Tooling

Pulsar provides a complete API layer for building secure, documented, and standards-compliant REST APIs. Every component enforces deny-by-default field exposure, strict input validation, and authorization-gated access.

## API resources

API resources are the **only** path for serializing data to API responses. Entities and ORM models cannot be returned directly from controllers.

### Defining a resource

```php
use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\Attribute\ApiResource;
use Pulsar\Api\Resource\Attribute\Expose;
use Pulsar\Api\Resource\Attribute\ClassificationTag;
use Pulsar\Security\Compliance\DataClassification;

#[ApiResource(type: 'users')]
class UserResource extends AbstractApiResource
{
    #[Expose]
    public string $id;

    #[Expose]
    public string $name;

    #[Expose]
    #[ClassificationTag(DataClassification::Confidential)]
    public string $email;

    // NOT exposed - denied by default
    public string $passwordHash;
    public string $internalNotes;
}
```

Fields without `#[Expose]` are **never** serialized. This is enforced at the framework level and cannot be bypassed.

### Sparse fieldsets

Clients can request specific fields using the `?fields=` parameter:

```
GET /api/v1/users?fields=id,name
```

Requesting an undeclared field returns `400 Bad Request`.

### Conditional fields

Fields can be conditionally included based on runtime logic:

```php
use Pulsar\Api\Resource\ConditionalField;

$resource->email = new ConditionalField(
    value: $user->email,
    include: $hasEmailPermission,
);
```

### Nested resources

Resources can embed other resources as properties:

```php
#[Expose]
public AddressResource $address;

#[Expose]
/** @var list<OrderResource> */
public array $recentOrders;
```

Nested resources respect the same deny-by-default rules and authorization checks.

## Field authorization

### Per-field permissions

Individual fields can require specific permissions or roles via `FieldPolicy`:

- **Required permissions**: All listed permissions must be present
- **Required roles**: At least one listed role must match
- **Classification tags**: Field classification must not exceed the requester's clearance

Unauthorized fields are **silently omitted** from the response. The `_redactions` metadata is only included when the caller has a debug/audit flag enabled, preventing information leakage about hidden fields.

### Classification tags

Fields tagged with data classification levels are filtered based on the request's clearance:

```php
#[Expose]
#[ClassificationTag(DataClassification::Restricted)]
public string $ssn;
```

The clearance snapshot is computed once at the request boundary and remains immutable for the entire request lifetime. This guarantees deterministic field exposure: the same request always produces the same fields.

### Redaction rules

Sensitive fields can define redaction rules for partial access:

```php
use Pulsar\Api\Resource\RedactionRule;
use Pulsar\Api\Resource\RedactionStrategy;

$rules = [
    'email' => new RedactionRule(RedactionStrategy::Mask, maskChar: '*', visibleChars: 3),
    // "john@example.com" → "joh***@example.com"
];
```

Strategies: `Mask`, `Truncate`, `Hash`.

## Filtering

Filters are explicitly registered per resource. Open-ended query parameters are not permitted.

### Registering filters

```php
use Pulsar\Api\Filter\Filter;
use Pulsar\Api\Filter\FilterRegistry;

$registry = new FilterRegistry();
$registry->register('users', [
    'status' => Filter::enum(UserStatus::class),
    'created_after' => Filter::date('created_at'),
    'name' => Filter::string(),
    'age' => Filter::integer(),
    'internal_status' => Filter::enum(InternalStatus::class)->guard('admin'),
]);
```

### Available operators

| Operator      | Description              |
| ------------- | ------------------------ |
| `eq`          | Equal                    |
| `neq`         | Not equal                |
| `gt`          | Greater than             |
| `gte`         | Greater than or equal    |
| `lt`          | Less than                |
| `lte`         | Less than or equal       |
| `in`          | In set (comma-separated) |
| `contains`    | Contains substring       |
| `starts_with` | Starts with prefix       |

### Query format

```
GET /api/v1/users?filter[status]=eq:active&filter[age]=gte:18
GET /api/v1/users?filter[role]=in:admin,editor
```

Unknown operators or fields return `400 Bad Request`. Authorization-guarded filters require the specified role.

### Safe query mapping

Each filter maps to a query builder expression. Raw SQL is never constructed from filter input. LIKE wildcards (`%`, `_`) are escaped to prevent injection.

## Sorting

Sorts are explicitly registered per resource, similar to filters.

### Registering sorts

```php
use Pulsar\Api\Sort\SortDefinition;
use Pulsar\Api\Sort\SortRegistry;

$registry = new SortRegistry();
$registry->register('users', [
    'name' => new SortDefinition(column: 'name'),
    'created_at' => new SortDefinition(column: 'created_at'),
    'salary' => new SortDefinition(column: 'salary', guard: 'admin'),
]);
```

### Query format

```
GET /api/v1/users?sort=name,-created_at
```

Prefix `-` for descending order. Unknown fields return `400 Bad Request`.

## Pagination

Three pagination strategies are available: offset, cursor, and keyset.

### Offset pagination

Traditional page-based pagination:

```
GET /api/v1/users?page=2&per_page=25
```

### Cursor pagination

Opaque cursor-based pagination, safe from enumeration:

```
GET /api/v1/users?cursor=Y3Vyc29yOjI1&per_page=25
```

### Keyset pagination

Efficient for large datasets using the last item's sort key:

```
GET /api/v1/users?after=eyJrIjoiNTAifQ==&per_page=25
```

### Response metadata

All pagination strategies return consistent metadata:

```json
{
  "data": [...],
  "meta": {
    "current_page": 2,
    "per_page": 25,
    "total": 150,
    "total_pages": 6,
    "has_more": true
  },
  "links": {
    "first": "/api/v1/users?page=1&per_page=25",
    "last": "/api/v1/users?page=6&per_page=25",
    "next": "/api/v1/users?page=3&per_page=25",
    "prev": "/api/v1/users?page=1&per_page=25"
  }
}
```

## Content negotiation

Pulsar supports three response formats via the `Accept` header:

| Accept Header              | Format   | Status   |
| -------------------------- | -------- | -------- |
| `application/json`         | JSON     | Core     |
| `application/vnd.api+json` | JSON:API | Optional |
| `application/hal+json`     | HAL      | Optional |

JSON is the default format. JSON:API and HAL are opt-in extensions that must be enabled in configuration.

The `ContentNegotiator` middleware reads the `Accept` header and selects the correct renderer. When no match is found, it falls back to the configured default.

## API versioning

Three versioning strategies are supported:

### URL prefix (default)

```
GET /api/v1/users
GET /api/v2/users
```

### Header

```
GET /api/users
Api-Version: 2
```

### Query parameter

```
GET /api/users?api-version=2
```

### Configuration

The versioning strategy, supported versions, and deprecation notices are configured in `ApiConfig`. Deprecated versions include a `Sunset` response header. Unsupported versions return `400 Bad Request`.

## OpenAPI generation

Pulsar generates OpenAPI v3 specs at **build time** as a versioned artifact. No runtime reflection is used for spec generation.

### Attributes

```php
use Pulsar\Api\OpenApi\Attribute\ApiDoc;
use Pulsar\Api\OpenApi\Attribute\ApiParam;
use Pulsar\Api\OpenApi\Attribute\ApiResponse;

#[ApiDoc(summary: 'List users', tags: ['Users'])]
#[ApiParam(name: 'status', in: 'query', type: 'string')]
#[ApiResponse(status: 200, description: 'User list')]
public function index(): ResourceCollection { ... }
```

### CLI commands

```bash
# Generate versioned OpenAPI spec
php pulsar api:spec

# List all API endpoints
php pulsar api:routes
```

### Vendor extensions

The generated spec includes compliance metadata:

- `x-pulsar-classification`: Per-field data classification level
- `x-pulsar-access-level`: Minimum role/clearance required
- `x-pulsar-redacted`: Whether a field may be omitted for unauthorized callers

### Swagger UI

The `SwaggerUiController` serves the pre-built spec at a configurable route. It does not generate the spec at runtime.

## Complexity limits

To prevent abuse and resource exhaustion, configurable complexity caps are enforced:

| Limit              | Default | Description                                   |
| ------------------ | ------- | --------------------------------------------- |
| Max fields         | 50      | Maximum fields per request via `?fields=`     |
| Max nesting depth  | 3       | Maximum depth for included/embedded resources |
| Max includes count | 10      | Maximum number of includes/relationships      |

Exceeding any limit returns `400 Bad Request` with a descriptive error message identifying which limit was exceeded. Limits are configurable per resource or globally via `ApiConfig`.

## Returning something that is not a response

A controller action must return a `ResponseInterface` (or a string, which the kernel wraps in an HTML response). Returning anything else -- a domain entity, an ORM model, an array -- is refused by `Kernel::invokeHandler()`, which throws `RoutingException::unexpectedReturnType()` before the value can be serialized. The client receives the configured error response and no entity data reaches the response body.

There is no toggle for this: `Kernel::invokeHandler()` has no branch that serializes the returned value, so on the full kernel the refusal is a property of the dispatch path rather than a setting. Wrap the entity in an `ApiResource` subclass to expose it deliberately.

It is a property of _that_ dispatch path, and the qualifier is load-bearing. `Pulsar\Core\MicroKernel`, the single-file kernel and part of the public API, dispatches the same handler shapes and ends differently: a returned array is passed to `Response::json()`, so a controller returning `['patient' => $patient]` emits every public property of the entity as JSON, with no `ApiResource` and no `FieldAuthorizer` between it and the wire. A returned entity object is not serialized there, but it is not refused either — it falls through to an empty `200 text/html`. An API that must not leak an entity by accident belongs on the full `Kernel`.

Note that the kernel inspects what the action _returns_, not what the action _puts in a response it builds_. `Response::json($entity)` serializes the entity, and nothing here stops it -- choosing what a resource exposes is the job of `ApiResource` and `FieldAuthorizer`.

## Configuration

```php
// config/api.php
return [
    'default_format' => 'json',
    'pagination' => [
        'type' => 'offset',
        'default_size' => 25,
        'max_size' => 100,
    ],
    'versioning_strategy' => 'url',
    'complexity_limits' => [
        'max_fields' => 50,
        'max_nesting_depth' => 3,
        'max_includes' => 10,
    ],
];
```
