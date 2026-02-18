# Route Model Binding

Route model binding automatically resolves route parameters into domain model instances. When a controller method type-hints a parameter whose name matches a route parameter, Pulsar resolves the corresponding model from your persistence layer before the controller executes.

## Architecture

### Key Components

| Class                        | Namespace                         | Purpose                                                  |
| ---------------------------- | --------------------------------- | -------------------------------------------------------- |
| `ModelResolverPort`          | `Pulsar\Routing\Binding\Contract` | Port for resolving models from route parameters          |
| `AuthorizationHookInterface` | `Pulsar\Routing\Binding\Contract` | Hook for authorizing access to resolved models           |
| `ModelBinder`                | `Pulsar\Routing\Binding`          | Orchestrates the binding pipeline                        |
| `BindingResolver`            | `Pulsar\Routing\Binding`          | Resolves binding metadata via compiled map or reflection |
| `ModelBindingMiddleware`     | `Pulsar\Routing\Binding`          | PSR-15 middleware that drives the binding process        |
| `ModelBindingConfig`         | `Pulsar\Routing\Binding`          | Configuration DTO with preset and key allowlist          |
| `ResolutionContext`          | `Pulsar\Routing\Binding`          | Context passed to resolvers (tenant, subject, trashed)   |
| `BindingMeta`                | `Pulsar\Routing\Binding`          | Metadata describing a single parameter binding           |
| `CompiledBindingMap`         | `Pulsar\Routing\Binding`          | Pre-compiled binding map for zero-reflection production  |
| `PolicyAuthorizationHook`    | `Pulsar\Routing\Binding`          | Default authorization hook delegating to the Gate        |
| `PublicRoute`                | `Pulsar\Routing\Attribute`        | Attribute to exempt a route from mandatory authorization |
| `ModelBindingException`      | `Pulsar\Routing\Binding`          | Exception type for binding failures                      |

### Request Pipeline

```
Request → AuthN Middleware → Router → ModelBindingMiddleware → Controller
                                        │
                                        ├─ Resolve binding metadata
                                        ├─ Validate & coerce key values
                                        ├─ Call ModelResolverPort
                                        ├─ Enforce authorization
                                        └─ Attach models to request
```

The middleware runs after authentication and routing but before the controller. Resolved models are available in the request as attributes.

---

## Implicit Binding

Type-hinted controller parameters are automatically resolved when their name matches a route parameter:

```php
// Route: /users/{user}
public function show(User $user): ResponseInterface
{
    // $user is automatically resolved from the {user} route parameter
    return Response::json($user);
}
```

Pulsar inspects the controller method signature via reflection. For each parameter whose name matches a route segment and whose type hint is a class, a `BindingMeta` is created and the model is resolved via `ModelResolverPort`.

Parameters with scalar types (`string`, `int`, etc.) or framework types (`Request`, `ResponseInterface`) are ignored.

---

## Explicit Binding

Register explicit parameter-to-model mappings via `Router::model()`. Explicit bindings always override implicit resolution:

```php
// Basic explicit binding
$router->model('user', User::class);

// With a custom resolver
$router->model('user', User::class, ApiUserResolver::class);
```

This is useful when the route parameter name does not match the controller type hint, or when a custom resolver is needed for a specific parameter.

---

## Custom Keys

By default, models are resolved by their `id` column. Use the `{param:key}` syntax in route paths to resolve by a different column:

```php
// Route: /users/{user:slug}
// Resolves User by the 'slug' column instead of 'id'
public function show(User $user): ResponseInterface
{
    // $user is resolved via: SELECT * FROM users WHERE slug = ?
}
```

### Allowed Key Names

For security, only allowlisted key names are permitted. The default allowlist is `['id', 'uuid', 'slug']`. Configure this in `ModelBindingConfig`:

```php
$config = ModelBindingConfig::fromArray([
    'allowed_key_names' => ['id', 'uuid', 'slug', 'code'],
]);
```

Using an unlisted key name results in a 400 Bad Request response.

---

## Scoped Bindings

Scoped bindings resolve a child model within the context of its parent relationship. This ensures that the child actually belongs to the parent:

```php
// Route: /users/{user}/posts/{post}
// The post is resolved within the user's posts relationship
public function show(User $user, Post $post): ResponseInterface
{
    // $post is guaranteed to belong to $user
}
```

Scoped resolution calls `ModelResolverPort::resolveScoped()`, which receives the parent model and the relationship name:

```php
$resolver->resolveScoped(
    modelClass: Post::class,
    keyName: 'id',
    keyValue: 42,
    parent: $user,
    relation: 'posts',
    context: $context,
);
```

If the child model does not exist within the parent relationship, a 404 response is returned.

---

## Soft-Deleted Models

The `ResolutionContext::$includeTrashed` property controls whether soft-deleted models are included in resolution. By default, soft-deleted models are excluded:

```php
readonly class ResolutionContext
{
    public function __construct(
        public ?string $tenantId = null,
        public ?string $subjectId = null,
        public bool $includeTrashed = false,
        public array $attributes = [],
    ) {}
}
```

Resolver implementations should check `$context->includeTrashed` and adjust their queries accordingly.

---

## Tenant-Scoped Resolution

When a `TenantContext` is available and a tenant has been resolved for the current request, the tenant ID is automatically passed to the resolver via `ResolutionContext::$tenantId`:

```php
// In your ModelResolverPort implementation:
public function resolve(
    string $modelClass,
    string $keyName,
    string|int $keyValue,
    ResolutionContext $context,
): ?object {
    $query = $this->queryBuilder->table($modelClass)->where($keyName, $keyValue);

    if ($context->tenantId !== null) {
        $query = $query->where('tenant_id', $context->tenantId);
    }

    return $query->first();
}
```

This integrates with Pulsar's multi-tenancy system. The `ModelBindingMiddleware` reads the `TenantContext` and populates the `ResolutionContext` automatically.

---

## Authorization

Route model binding integrates with Pulsar's authorization system. After a model is resolved, the `AuthorizationHookInterface` checks whether the authenticated identity is permitted to access it.

### Regulated Presets

When `ModelBindingConfig::$preset` is set to a regulated preset (`banking`, `healthcare`, or `legal`), authorization is **mandatory** on every bound model:

- **Missing identity**: Returns 401 Unauthorized.
- **Authorization denied**: Returns 403 Forbidden and logs an audit event.
- **Missing policy**: Hard error. Every model must have a configured authorization policy.
- **`withoutAuthorization()` bypass**: Forbidden unless `#[PublicRoute]` is present on the handler.

### Permissive Preset (Standard)

With the default `standard` preset, authorization is opt-in:

- **Missing identity**: Binding proceeds with a debug log warning.
- **Authorization denied**: Returns 403 Forbidden.
- **`withoutAuthorization()` bypass**: Allowed without restriction.

### Default Authorization Hook

The `PolicyAuthorizationHook` delegates to the Gate with a `view` permission and the resolved model as context:

```php
final readonly class PolicyAuthorizationHook implements AuthorizationHookInterface
{
    public function __construct(
        private GateInterface $gate,
    ) {}

    public function authorize(IdentityInterface $identity, object $model, BindingMeta $meta): bool
    {
        $permission = $meta->authzPolicy ?? 'view';

        $context = new PolicyContext(
            permission: $permission,
            resource: $meta->class,
            attributes: ['model' => $model],
        );

        return $this->gate->allows($identity, $permission, $context);
    }
}
```

### Audit Logging

Authorization denials are recorded via `AuditLoggerInterface` when configured:

| Field    | Value                         |
| -------- | ----------------------------- |
| Event    | `AuditEvent::Authorization`   |
| Outcome  | `AuditOutcome::Denied`        |
| Action   | `model_binding_authorization` |
| Resource | Request URI path              |
| Metadata | `['model' => $modelClass]`    |

---

## `#[PublicRoute]` Attribute

The `#[PublicRoute]` attribute marks a route handler as intentionally exempt from model binding authorization. It can be applied at the method or class level:

```php
use Pulsar\Routing\Attribute\PublicRoute;

#[PublicRoute(reason: 'Public product listing')]
public function index(): ResponseInterface
{
    // No authorization check on resolved models
}
```

The `reason` parameter documents why the route is public, providing an audit trail for compliance reviews.

In regulated presets, `#[PublicRoute]` is the **only** way to bypass authorization on bound models. Attempting to use `withoutAuthorization()` without this attribute results in a 403 Forbidden response.

---

## Compiled Binding Metadata

### Production Mode

In production, enable `compiledMode` to load binding metadata from a pre-built `CompiledBindingMap`. This eliminates per-request reflection:

```php
$config = ModelBindingConfig::fromArray([
    'compiled_mode' => true,
]);
```

The compiled map is a PHP array keyed by route name, with each entry containing the binding metadata for that route's parameters. Build it with the framework's optimize command:

```bash
php bin/pulsar optimize
```

### Development Mode

In development (the default), binding metadata is resolved on-the-fly via reflection on controller type hints. This requires no build step but incurs a small per-request overhead.

---

## Custom Resolvers

Implement `ModelResolverPort` to customize how models are resolved from route parameters:

```php
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ResolutionContext;

class ApiUserResolver implements ModelResolverPort
{
    public function resolve(
        string $modelClass,
        string $keyName,
        string|int $keyValue,
        ResolutionContext $context,
    ): ?object {
        // Custom resolution logic (e.g., from API, cache, read model)
        return $this->apiClient->findUser($keyValue);
    }

    public function resolveScoped(
        string $modelClass,
        string $keyName,
        string|int $keyValue,
        object $parent,
        string $relation,
        ResolutionContext $context,
    ): ?object {
        // Scoped resolution: resolve within parent relationship
        return $this->apiClient->findUserChild($parent->id, $relation, $keyValue);
    }
}
```

Register a custom resolver for a specific route parameter via explicit binding:

```php
$router->model('user', User::class, ApiUserResolver::class);
```

The custom resolver is resolved from the container, so it supports constructor injection.

---

## Strict Key Coercion

Route parameter values are strictly validated against the declared key type. Non-integer values for integer-typed parameters result in an immediate 404 response with no loose coercion:

| Input   | Key Type | Result          |
| ------- | -------- | --------------- |
| `"42"`  | `int`    | Coerced to `42` |
| `"abc"` | `int`    | 404 Not Found   |
| `"-1"`  | `int`    | 404 Not Found   |
| `"0"`   | `int`    | Coerced to `0`  |
| `"foo"` | `string` | Passed as-is    |

This prevents a class of bugs where `"abc"` would be silently cast to `0` and resolve the wrong record.

---

## Configuration

`ModelBindingConfig` is a readonly DTO with the following options:

```php
readonly class ModelBindingConfig
{
    public function __construct(
        public string $preset = 'standard',
        public ?string $authorizationHook = null,
        public array $allowedKeyNames = ['id', 'uuid', 'slug'],
        public bool $compiledMode = false,
    ) {}

    public static function fromArray(array $data): self;
    public function isRegulatedPreset(): bool;
}
```

### Options

| Option               | Type            | Default                  | Description                                                        |
| -------------------- | --------------- | ------------------------ | ------------------------------------------------------------------ |
| `preset`             | `string`        | `'standard'`             | Authorization preset: `standard`, `banking`, `healthcare`, `legal` |
| `authorization_hook` | `class-string?` | `null`                   | Custom `AuthorizationHookInterface` implementation class           |
| `allowed_key_names`  | `list<string>`  | `['id', 'uuid', 'slug']` | Allowlist for custom key names in `{param:key}` syntax             |
| `compiled_mode`      | `bool`          | `false`                  | Use pre-compiled binding map (zero reflection)                     |

### Example Configuration

```php
// config/model_binding.php
return [
    'preset' => 'banking',
    'authorization_hook' => CustomAuthHook::class,
    'allowed_key_names' => ['id', 'uuid', 'slug', 'code'],
    'compiled_mode' => true,
];
```

---

## ResolutionContext

The `ResolutionContext` is passed to every resolver call and carries request-scoped state:

```php
readonly class ResolutionContext
{
    public function __construct(
        public ?string $tenantId = null,
        public ?string $subjectId = null,
        public bool $includeTrashed = false,
        public array $attributes = [],
    ) {}
}
```

| Property          | Type                   | Description                                      |
| ----------------- | ---------------------- | ------------------------------------------------ |
| `$tenantId`       | `?string`              | Current tenant ID from `TenantContext`           |
| `$subjectId`      | `?string`              | Authenticated identity ID from `SecurityContext` |
| `$includeTrashed` | `bool`                 | Whether to include soft-deleted models           |
| `$attributes`     | `array<string, mixed>` | Arbitrary attributes for custom resolver logic   |

The `ModelBindingMiddleware` builds this context automatically from the current request's `TenantContext` and `SecurityContext`.

---

## Error Handling

All binding failures throw `ModelBindingException`, which carries an HTTP status code. The middleware translates these into appropriate HTTP responses:

| Factory Method          | HTTP Status        | When Thrown                                        |
| ----------------------- | ------------------ | -------------------------------------------------- |
| `modelNotFound()`       | 404 Not Found      | Resolver returned `null` for the given key         |
| `invalidKeyType()`      | 404 Not Found      | Route parameter value does not match declared type |
| `invalidKeyName()`      | 400 Bad Request    | Custom key name is not in the allowlist            |
| `authorizationFailed()` | 403 Forbidden      | Authorization hook denied access                   |
| `authBypassForbidden()` | 403 Forbidden      | Authorization bypass attempted on regulated route  |
| `missingPolicy()`       | 500 Internal Error | Regulated preset with no configured policy         |
| `missingResolver()`     | 500 Internal Error | No resolver registered for the model class         |

The middleware returns JSON responses when the request `Accept` header contains `application/json`, and plain text otherwise.

---

## Accessing Resolved Models

After the middleware runs, resolved models are available on the request as attributes:

```php
// Individual model by parameter name
$user = $request->getAttribute('_model_user');

// All resolved models as an associative array
$models = $request->getAttribute('_bound_models');
// ['user' => User, 'post' => Post, ...]
```

In a controller with type-hinted parameters, models are injected automatically — no manual attribute access is needed.
