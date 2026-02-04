# Multi-Tenancy

## Overview

Pulsar provides first-class multi-tenancy support designed for regulated, mission-critical applications where strict data isolation between tenants is non-negotiable. The tenancy system resolves the current tenant from incoming HTTP requests, sets a request-scoped `TenantContext`, and optionally enforces database isolation via table-prefix, separate-connection, or shared-database strategies.

Multi-tenancy in Pulsar is explicit: tenants must be pre-configured, resolution strategies are declarative, and every database access can be scoped automatically without hidden global state.

### Key components

| Class | Namespace | Purpose |
|---|---|---|
| `Tenant` | `Pulsar\Tenancy` | Immutable value object representing a tenant |
| `TenantContext` | `Pulsar\Tenancy` | Request-scoped container for the current tenant |
| `TenantResolverInterface` | `Pulsar\Tenancy` | Contract for tenant resolution from requests |
| `TenantResolverStrategy` | `Pulsar\Tenancy` | Enum: `Header`, `Subdomain`, `Path` |
| `TenantDatabaseStrategy` | `Pulsar\Tenancy` | Enum: `Prefix`, `SeparateConnection`, `Shared` |
| `TenantResolutionMiddleware` | `Pulsar\Tenancy\Middleware` | HTTP middleware that resolves and sets tenant |
| `TenantAwareConnectionManager` | `Pulsar\Tenancy` | Database connection decorator for tenant isolation |
| `TenancyConfig` | `Pulsar\Config` | Typed configuration DTO |
| `TenantDatabaseConfig` | `Pulsar\Config` | Database isolation sub-configuration DTO |
| `TenancyException` | `Pulsar\Tenancy\Exception` | Exception type for tenancy errors |

---

## Configuration Reference

All tenancy settings live in `config/tenancy.php`. The `TENANCY_ENABLED` environment variable overrides the `enabled` key.

```php
// config/tenancy.php
return [
    'enabled' => false,

    // Resolver strategy: 'header', 'subdomain', or 'path'
    'resolver' => 'header',

    // HTTP header used when resolver is 'header'
    'header_name' => 'X-Tenant-ID',

    // Base domain suffix when resolver is 'subdomain'
    // Example: '.example.com' means 'acme.example.com' resolves to tenant 'acme'
    'subdomain_suffix' => '',

    // URL path prefix when resolver is 'path'
    // Example: '/t/' means '/t/acme/dashboard' resolves to tenant 'acme'
    'path_prefix' => '/t/',

    // Fallback tenant when no tenant can be resolved. Set to null to require explicit resolution.
    'default_tenant' => null,

    // Database isolation settings
    'database' => [
        // Strategy: 'prefix', 'separate_connection', or 'shared'
        'strategy' => 'prefix',
        // Template for table prefixes. {tenant_id} is replaced at runtime.
        'prefix_template' => 'tenant_{tenant_id}_',
    ],

    // Map of tenant ID => tenant data
    'tenants' => [
        // 'acme' => [
        //     'name' => 'Acme Corp',
        //     'metadata' => ['plan' => 'enterprise'],
        // ],
    ],
];
```

### Configuration DTO

The raw array is parsed into a typed `TenancyConfig` DTO at boot time:

```php
use Pulsar\Config\TenancyConfig;
use Pulsar\Config\TenantDatabaseConfig;
use Pulsar\Tenancy\TenantResolverStrategy;
use Pulsar\Tenancy\TenantDatabaseStrategy;

// TenancyConfig properties:
readonly class TenancyConfig
{
    public function __construct(
        public bool $enabled = false,
        public TenantResolverStrategy $resolver = TenantResolverStrategy::Header,
        public string $headerName = 'X-Tenant-ID',
        public string $subdomainSuffix = '',
        public string $pathPrefix = '/t/',
        public ?string $defaultTenant = null,
        public TenantDatabaseConfig $database = new TenantDatabaseConfig(),
        public array $tenants = [],
    ) {}
}

// TenantDatabaseConfig properties:
readonly class TenantDatabaseConfig
{
    public function __construct(
        public TenantDatabaseStrategy $strategy = TenantDatabaseStrategy::Prefix,
        public string $prefixTemplate = 'tenant_{tenant_id}_',
    ) {}
}
```

---

## Tenant Value Object

The `Tenant` class is an immutable, readonly value object carrying the tenant's identity and metadata:

```php
use Pulsar\Tenancy\Tenant;

// Direct construction
$tenant = new Tenant(
    id: 'acme',
    name: 'Acme Corp',
    metadata: ['plan' => 'enterprise', 'region' => 'us-east'],
);

// From a raw config array
$tenant = Tenant::fromArray('acme', [
    'name' => 'Acme Corp',
    'metadata' => ['plan' => 'enterprise'],
]);

// Access properties
$tenant->id;       // 'acme'
$tenant->name;     // 'Acme Corp'
$tenant->metadata; // ['plan' => 'enterprise', 'region' => 'us-east']
```

---

## Resolver Strategies

Pulsar ships three built-in resolver implementations. All implement `TenantResolverInterface`:

```php
interface TenantResolverInterface
{
    public function resolve(Request $request): ?Tenant;
}
```

The resolver returns `null` when no tenant can be identified, or when the extracted tenant ID does not match any entry in the configured tenants map.

### Header Resolver

**Class:** `Pulsar\Tenancy\Resolver\HeaderTenantResolver`

Extracts the tenant ID from a configurable HTTP request header. This is the default strategy and is well suited for API-first architectures and service-to-service communication.

**Configuration:**

```php
'resolver' => 'header',
'header_name' => 'X-Tenant-ID',
```

**How it works:**

1. Reads the header specified by `TenancyConfig::$headerName` from the request.
2. If the header is absent or empty, returns `null`.
3. Looks up the header value in the configured tenants map.
4. If found, returns a `Tenant` value object; otherwise returns `null`.

**Example request:**

```
GET /api/accounts HTTP/1.1
Host: api.example.com
X-Tenant-ID: acme
```

**Setup:**

```php
use Pulsar\Config\TenancyConfig;
use Pulsar\Tenancy\Resolver\HeaderTenantResolver;

$config = new TenancyConfig(
    enabled: true,
    headerName: 'X-Tenant-ID',
    tenants: [
        'acme' => ['name' => 'Acme Corp'],
        'globex' => ['name' => 'Globex Inc'],
    ],
);

$resolver = new HeaderTenantResolver($config);
$tenant = $resolver->resolve($request); // Tenant or null
```

### Subdomain Resolver

**Class:** `Pulsar\Tenancy\Resolver\SubdomainTenantResolver`

Extracts the tenant ID from the subdomain portion of the `Host` header. Ideal for SaaS applications with tenant-specific subdomains.

**Configuration:**

```php
'resolver' => 'subdomain',
'subdomain_suffix' => '.example.com',
```

**How it works:**

1. Reads the `Host` header and strips any port number.
2. Verifies that the host ends with the configured `subdomainSuffix`.
3. Extracts the portion before the suffix as the tenant ID.
4. Looks up the tenant ID in the configured tenants map.

**Example request:**

```
GET /dashboard HTTP/1.1
Host: acme.example.com
```

This resolves to tenant `acme`.

**Setup:**

```php
use Pulsar\Config\TenancyConfig;
use Pulsar\Tenancy\Resolver\SubdomainTenantResolver;

$config = new TenancyConfig(
    enabled: true,
    resolver: \Pulsar\Tenancy\TenantResolverStrategy::Subdomain,
    subdomainSuffix: '.myapp.com',
    tenants: [
        'acme' => ['name' => 'Acme Corp'],
        'globex' => ['name' => 'Globex Inc'],
    ],
);

$resolver = new SubdomainTenantResolver($config);
$tenant = $resolver->resolve($request);
```

### Path Prefix Resolver

**Class:** `Pulsar\Tenancy\Resolver\PathPrefixTenantResolver`

Extracts the tenant ID from a URL path prefix segment. Useful when DNS-level tenant routing is not available, or for applications that embed the tenant ID in the URL structure.

**Configuration:**

```php
'resolver' => 'path',
'path_prefix' => '/t/',
```

**How it works:**

1. Checks whether the request path starts with the configured `pathPrefix`.
2. Extracts the first path segment after the prefix as the tenant ID.
3. Looks up the tenant ID in the configured tenants map.

**Example request:**

```
GET /t/acme/dashboard HTTP/1.1
Host: example.com
```

This resolves to tenant `acme`. The remaining path after the tenant segment (`/dashboard`) is preserved for routing.

**Setup:**

```php
use Pulsar\Config\TenancyConfig;
use Pulsar\Tenancy\Resolver\PathPrefixTenantResolver;

$config = new TenancyConfig(
    enabled: true,
    resolver: \Pulsar\Tenancy\TenantResolverStrategy::Path,
    pathPrefix: '/org/',
    tenants: [
        'acme' => ['name' => 'Acme Corp'],
        'globex' => ['name' => 'Globex Inc'],
    ],
);

$resolver = new PathPrefixTenantResolver($config);
$tenant = $resolver->resolve($request);
```

---

## Database Isolation Strategies

The `TenantDatabaseStrategy` enum defines three approaches to database isolation:

| Strategy | Enum Value | Description |
|---|---|---|
| `Prefix` | `'prefix'` | All tenants share a database; tables are prefixed with a tenant-specific string |
| `SeparateConnection` | `'separate_connection'` | Each tenant gets a dedicated database connection (e.g., separate databases) |
| `Shared` | `'shared'` | All tenants share the same database and tables (application-level filtering) |

### Prefix Strategy

The default strategy. Tables are prefixed using the `prefix_template` pattern, where `{tenant_id}` is replaced with the actual tenant ID at runtime.

```php
'database' => [
    'strategy' => 'prefix',
    'prefix_template' => 'tenant_{tenant_id}_',
],
```

For tenant `acme`, `getTablePrefix()` returns `tenant_acme_`. Application code and migrations should prepend this prefix to all table names.

### Separate Connection Strategy

Each tenant uses a distinct database connection named `tenant_{tenant_id}`. These connections must be pre-configured in your database configuration.

```php
'database' => [
    'strategy' => 'separate_connection',
],
```

When the `TenantAwareConnectionManager` is asked for a connection and a tenant is resolved, it redirects to the connection named `tenant_acme` (for tenant `acme`).

### Shared Strategy

All tenants share the same database and tables. No prefix is applied, and no connection switching occurs. Data isolation is the application's responsibility (typically via a `tenant_id` column and query scoping).

```php
'database' => [
    'strategy' => 'shared',
],
```

---

## TenantContext API

`TenantContext` is the request-scoped container that holds the resolved tenant for the current request lifecycle.

```php
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\Exception\TenancyException;

$context = new TenantContext();

// Set the current tenant (typically done by middleware)
$context->set($tenant);

// Get the current tenant (throws TenancyException if not resolved)
$tenant = $context->get();

// Get the current tenant without throwing (returns null if not resolved)
$tenant = $context->tryGet();

// Check if a tenant has been resolved
if ($context->isResolved()) {
    // tenant is available
}

// Clear the tenant (e.g., at end of request)
$context->clear();
```

### Method Reference

| Method | Return Type | Throws | Description |
|---|---|---|---|
| `set(Tenant $tenant)` | `void` | -- | Set the current tenant |
| `get()` | `Tenant` | `TenancyException` | Get current tenant or throw |
| `tryGet()` | `?Tenant` | -- | Get current tenant or null |
| `isResolved()` | `bool` | -- | Check if a tenant is set |
| `clear()` | `void` | -- | Remove the current tenant |

---

## TenantResolutionMiddleware

**Class:** `Pulsar\Tenancy\Middleware\TenantResolutionMiddleware`

This middleware bridges tenant resolution into the HTTP request pipeline. It should be registered early in the middleware stack so that downstream handlers can access the resolved tenant.

### Behavior

1. Calls the configured `TenantResolverInterface` to attempt resolution from the request.
2. If no tenant is resolved and a `defaultTenant` is configured, falls back to the default.
3. If a tenant is resolved:
   - Sets the tenant on `TenantContext` via `set()`.
   - Attaches the tenant to the request as the `_tenant` attribute (accessible via `$request->attribute('_tenant')`).
   - Logs the resolution event.
4. If no tenant is resolved (and no default is configured), logs a notice and passes the request through without a tenant context.
5. Calls the next middleware in the pipeline.

### Constructor

```php
public function __construct(
    private TenantResolverInterface $resolver,
    private TenantContext $context,
    private TenancyConfig $config,
    private ?LoggerInterface $logger = null,
)
```

### Usage

```php
use Pulsar\Tenancy\Middleware\TenantResolutionMiddleware;
use Pulsar\Tenancy\Resolver\HeaderTenantResolver;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Config\TenancyConfig;

$config = TenancyConfig::fromArray($rawConfig, $environment);
$context = new TenantContext();
$resolver = new HeaderTenantResolver($config);

$middleware = new TenantResolutionMiddleware(
    resolver: $resolver,
    context: $context,
    config: $config,
    logger: $logger,
);

// Register in the middleware pipeline
$pipeline->pipe($middleware);
```

After the middleware runs, you can access the tenant from the request:

```php
// In a controller or downstream middleware
$tenant = $request->attribute('_tenant'); // Tenant|null
```

---

## TenantAwareConnectionManager

**Class:** `Pulsar\Tenancy\TenantAwareConnectionManager`

A decorator around `ConnectionManagerInterface` that automatically applies tenant-scoped database isolation. It implements `ConnectionManagerInterface` itself, so it is a drop-in replacement.

### Constructor

```php
public function __construct(
    private readonly ConnectionManagerInterface $inner,
    private readonly TenantContext $context,
    private readonly TenancyConfig $config,
)
```

### Behavior by Strategy

**Prefix strategy:** The `connection()` method delegates to the inner manager unchanged. Use `getTablePrefix()` to obtain the tenant-specific table prefix for query building.

```php
$manager = new TenantAwareConnectionManager($inner, $context, $config);
$connection = $manager->connection();
$prefix = $manager->getTablePrefix(); // e.g., 'tenant_acme_'
$connection->query("SELECT * FROM {$prefix}users WHERE active = 1");
```

**Separate connection strategy:** When a tenant is resolved, `connection()` automatically redirects to the connection named `tenant_{tenantId}`.

```php
// With tenant 'acme' resolved, this returns the 'tenant_acme' connection
$connection = $manager->connection();
```

**Shared strategy:** The `connection()` method delegates to the inner manager unchanged. `getTablePrefix()` returns an empty string.

### Method Reference

| Method | Return Type | Description |
|---|---|---|
| `connection(?string $name = null)` | `ConnectionInterface` | Get a connection (tenant-aware routing for SeparateConnection strategy) |
| `getDefaultConnectionName()` | `string` | Delegates to the inner connection manager |
| `disconnect(?string $name = null)` | `void` | Delegates to the inner connection manager |
| `getTablePrefix()` | `string` | Get the tenant table prefix (Prefix strategy only; throws if no tenant resolved) |

---

## Complete Setup Example

The following demonstrates end-to-end multi-tenancy configuration with the header resolver and prefix database strategy.

### Step 1: Configure tenants

```php
// config/tenancy.php
return [
    'enabled' => true,
    'resolver' => 'header',
    'header_name' => 'X-Tenant-ID',
    'default_tenant' => null,

    'database' => [
        'strategy' => 'prefix',
        'prefix_template' => 'tenant_{tenant_id}_',
    ],

    'tenants' => [
        'acme' => [
            'name' => 'Acme Corp',
            'metadata' => ['plan' => 'enterprise', 'region' => 'us-east'],
        ],
        'globex' => [
            'name' => 'Globex Inc',
            'metadata' => ['plan' => 'starter', 'region' => 'eu-west'],
        ],
    ],
];
```

### Step 2: Wire the resolver and middleware

```php
use Pulsar\Config\TenancyConfig;
use Pulsar\Config\Environment;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\Resolver\HeaderTenantResolver;
use Pulsar\Tenancy\Middleware\TenantResolutionMiddleware;
use Pulsar\Tenancy\TenantAwareConnectionManager;

// Build config DTO
$config = TenancyConfig::fromArray(
    require 'config/tenancy.php',
    new Environment(),
);

// Create shared context
$context = new TenantContext();

// Create resolver
$resolver = new HeaderTenantResolver($config);

// Create middleware
$middleware = new TenantResolutionMiddleware(
    resolver: $resolver,
    context: $context,
    config: $config,
    logger: $logger,
);

// Wrap the connection manager for tenant-aware database access
$tenantConnections = new TenantAwareConnectionManager(
    inner: $connectionManager,
    context: $context,
    config: $config,
);
```

### Step 3: Use in application code

```php
// In a controller, after the middleware has run:
class AccountController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantAwareConnectionManager $db,
    ) {}

    public function list(): Response
    {
        $tenant = $this->tenantContext->get();
        $prefix = $this->db->getTablePrefix();

        $accounts = $this->db->connection()
            ->query("SELECT * FROM {$prefix}accounts WHERE status = 'active'");

        // $tenant->id, $tenant->name, and $tenant->metadata
        // are available for business logic
    }
}
```

---

## Error Handling

All tenancy-specific errors throw `Pulsar\Tenancy\Exception\TenancyException`:

| Factory Method | When Thrown |
|---|---|
| `TenancyException::tenantNotResolved()` | `TenantContext::get()` called before a tenant is set |
| `TenancyException::tenantNotFound(string $identifier)` | A tenant ID was extracted but does not match any configured tenant |
| `TenancyException::invalidConfiguration(string $reason)` | Tenancy configuration is malformed |

---

## Environment Variable Overrides

| Variable | Overrides | Values |
|---|---|---|
| `TENANCY_ENABLED` | `config.tenancy.enabled` | `'true'` or `'false'` |
