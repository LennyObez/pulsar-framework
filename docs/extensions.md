# Extension system guide

Pulsar's extension system provides modular, manifest-driven extensibility with a deterministic four-phase lifecycle. Extensions are the primary mechanism for adding functionality to a Pulsar application - including routing, DI bindings, console commands, middleware, and migrations.

This is an original design. It is not a clone of Laravel service providers or Symfony bundles. The system enforces explicit capability declaration, strong versioning, compatibility checks, and a "zero entropy" principle: there is one obvious way to wire an extension.

## Architecture overview

### Core concepts

- **Manifest-driven**: Every extension declares its capabilities in a `pulsar.json` file.
- **Four-phase lifecycle**: Extensions progress through Register, PreBoot (optional), Boot, and PostBoot (optional) phases.
- **Deterministic boot pipeline**: Extensions are discovered, validated, dependency-sorted, and booted in a predictable order. All four lifecycle phases use the same dependency-resolved extension order.
- **No privileged access**: Built-in framework features use the same extension API as third-party code. The Studio development console is a first-party extension using these lifecycle hooks.

### Lifecycle states

Extensions progress through these states during the boot process, defined by the `ExtensionLifecycle` enum:

| State        | Description                                             |
| ------------ | ------------------------------------------------------- |
| `Discovered` | Extension manifest found and parsed                     |
| `Validated`  | Manifest validated, version compatibility confirmed     |
| `Registered` | Services bound to the DI container                      |
| `Booted`     | Routes registered, initialization complete              |
| `Failed`     | An error occurred (can happen from any preceding state) |

State transitions are enforced: an extension can only register from the `Validated` state and can only boot from the `Registered` state.

The boot pipeline executes four separate passes, each iterating over extensions in the same dependency-resolved order:

```
Pass 1 (Register):  foreach extensions → call register()
Pass 2 (PreBoot):   foreach extensions → if PreBootExtensionInterface → call preBoot()
Pass 3 (Boot):      foreach extensions → call boot()
Pass 4 (PostBoot):  foreach extensions → if PostBootExtensionInterface → call postBoot()
```

**Invariant:** PreBoot runs only after ALL extensions have registered their bindings. This ensures preBoot can safely resolve any service registered by any extension. Similarly, PostBoot runs only after ALL extensions have booted.

## Creating an extension

### Step 1: extension directory

Create a directory under `extensions/` (or use the scaffold command):

```bash
php bin/pulsar make:extension my-feature --vendor=acme
```

This generates:

```
extensions/my-feature/
  pulsar.json
  composer.json
  src/
    MyFeatureExtension.php
    MyFeatureServiceProvider.php
    MyFeatureService.php
    Controller/
      MyFeatureController.php
```

### Step 2: the manifest (pulsar.json)

Every extension must have a `pulsar.json` manifest at its root. This file declares the extension's identity, version, capabilities, and dependencies.

```json
{
  "name": "acme/my-feature",
  "version": "0.1.0",
  "description": "A Pulsar Framework extension",
  "extension_class": "Acme\\MyFeature\\MyFeatureExtension",
  "pulsar": {
    "min_version": "1.0.0"
  },
  "provides": {
    "services": ["MyFeatureService"],
    "routes": true,
    "commands": [],
    "middleware": []
  },
  "requires": {}
}
```

#### Manifest fields

| Field             | Type   | Required | Description                                                  |
| ----------------- | ------ | -------- | ------------------------------------------------------------ |
| `name`            | string | Yes      | Vendor-prefixed extension name (e.g., `acme/my-feature`)     |
| `version`         | string | Yes      | Semver version (e.g., `1.0.0`, `0.3.0-beta`)                 |
| `description`     | string | No       | Human-readable description                                   |
| `extension_class` | string | Yes      | FQCN of the class implementing `ExtensionInterface`          |
| `pulsar`          | object | No       | Framework version constraints                                |
| `provides`        | object | No       | Capabilities this extension offers                           |
| `requires`        | object | No       | Map of extension names to version constraints (dependencies) |

The `pulsar` object supports `min_version` and `max_version` fields for framework compatibility. The version format must follow semver (`MAJOR.MINOR.PATCH` with optional prerelease suffix).

The `provides` object declares:

- `services`: List of service class names registered in the container.
- `routes`: Boolean indicating whether the extension registers routes.
- `commands`: List of console command class names.
- `middleware`: List of middleware class names.

### Step 3: implement ExtensionInterface

The extension class must implement `Pulsar\Extensibility\ExtensionInterface`:

```php
<?php

declare(strict_types=1);

namespace Acme\MyFeature;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;
use Acme\MyFeature\Controller\MyFeatureController;

final class MyFeatureExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'acme/my-feature';
    }

    public function register(ContainerInterface $container): void
    {
        // Register services directly, or leave empty if using providers
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // Register routes
        $router->get('/my-feature', [MyFeatureController::class, 'index'], 'my-feature.index');
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            MyFeatureServiceProvider::class,
        ];
    }
}
```

#### Interface methods

| Method        | Phase    | Purpose                                                          |
| ------------- | -------- | ---------------------------------------------------------------- |
| `name()`      | --       | Returns the unique extension name (must match `pulsar.json`)     |
| `register()`  | Register | Bind services, interfaces, and factories to the DI container     |
| `boot()`      | Boot     | Register routes, configure services, perform initialization      |
| `providers()` | Register | Return class names of `ServiceProviderInterface` implementations |

The `register()` method is called first for all extensions before any `boot()` method runs. This guarantees that all services are available during the boot phase.

### Lifecycle hook interfaces (optional)

Extensions can implement additional interfaces for pre-boot and post-boot hooks:

#### PreBootExtensionInterface

Called after ALL extensions have registered but BEFORE any `boot()` runs. Use this to create services that must be available during other extensions' boot phase.

```php
use Pulsar\Extensibility\PreBootExtensionInterface;

final class MyExtension implements ExtensionInterface, PreBootExtensionInterface
{
    public function preBoot(ContainerInterface $container): void
    {
        // Create core services, load config, build DTOs
        // All extensions have registered - you can resolve any service
    }
}
```

#### PostBootExtensionInterface

Called after ALL extensions (including the caller) have booted. Use this to wire decorators, collectors, or observers into the final post-boot state of services.

```php
use Pulsar\Extensibility\PostBootExtensionInterface;

final class MyExtension implements ExtensionInterface, PostBootExtensionInterface
{
    public function postBoot(ContainerInterface $container): void
    {
        // Wire collectors, decorators, or observers into final service bindings
        // All extensions have booted - you can safely wrap services
    }
}
```

Both interfaces are optional. Extensions that do not implement them are simply skipped during those phases.

#### Piping global middleware

Extensions that need to add global middleware can resolve `MiddlewarePipelineInterface` from the container during `boot()` or `postBoot()`:

```php
use Pulsar\Http\Middleware\MiddlewarePipelineInterface;

public function postBoot(ContainerInterface $container): void
{
    if ($container->has(MiddlewarePipelineInterface::class)) {
        $pipeline = $container->get(MiddlewarePipelineInterface::class);
        $pipeline->pipe(new MyMiddleware());
    }
}
```

The `pipe()` method appends middleware (FIFO). Middleware piped later runs closer to the handler (innermost). Use `postBoot()` to pipe middleware that should wrap the final state of all services.

## Service providers

Service providers encapsulate reusable service registration logic. They implement `Pulsar\Extensibility\ServiceProviderInterface`:

```php
<?php

declare(strict_types=1);

namespace Acme\MyFeature;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;

final class MyFeatureServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(MyFeatureService::class, MyFeatureService::class);
    }

    public function provides(): array
    {
        return [
            MyFeatureService::class,
        ];
    }
}
```

The `provides()` method returns a list of service IDs that this provider registers. This is used for deferred loading and debugging. Return an empty array if you do not want to advertise services.

Service providers listed in an extension's `providers()` method are instantiated and their `register()` methods are called during the registration phase.

## Extension capabilities

### Routing

Extensions that declare `"routes": true` in their manifest can register routes in the `boot()` method:

```php
public function boot(ContainerInterface $container, RouterInterface $router): void
{
    $router->get('/api/widgets', [WidgetController::class, 'list'], 'widgets.list');
    $router->post('/api/widgets', [WidgetController::class, 'create'], 'widgets.create');
    $router->get('/api/widgets/{id}', [WidgetController::class, 'show'], 'widgets.show');
}
```

### DI bindings

Register services in `register()` or via service providers:

```php
public function register(ContainerInterface $container): void
{
    $container->singleton(CacheInterface::class, fn() => new RedisCache());
    $container->bind(WidgetRepository::class, WidgetRepository::class);
}
```

### Console commands

Extensions can provide console commands by listing them in the `provides.commands` manifest field. Commands implement `Pulsar\Console\CommandInterface` and are registered during the boot phase.

### Middleware

Extensions can provide middleware by listing class names in the `provides.middleware` manifest field. Middleware classes implement `Pulsar\Http\Middleware\MiddlewareInterface`.

### Migrations

Extensions can bundle database migrations. Migration files follow the naming convention `{YYYYMMDDHHmmss}_{name}.php` and implement `Pulsar\Database\Migration\MigrationInterface`.

## Versioning and compatibility

### Framework version constraints

The `pulsar` section of the manifest specifies which framework versions the extension supports:

```json
{
  "pulsar": {
    "min_version": "1.0.0",
    "max_version": "2.0.0"
  }
}
```

During validation, the framework checks `PulsarVersionConfig::isSatisfiedByCurrent()` against the running Pulsar version. Extensions that fail this check transition to the `Failed` state and are not loaded.

### Extension dependencies

Extensions can depend on other extensions using the `requires` field:

```json
{
  "requires": {
    "acme/auth": "^1.0.0",
    "acme/cache": "^2.0.0"
  }
}
```

The extension system resolves dependencies and boots extensions in the correct order. Circular dependencies are detected and reported as errors.

### Semver rules

- Extension versions must follow semver: `MAJOR.MINOR.PATCH` with optional prerelease suffix.
- The manifest parser validates version format and rejects invalid versions with `ManifestException::invalidVersion()`.
- Dependency version constraints follow standard semver range notation.

## Example: complete extension walkthrough

This walkthrough builds a "Notifications" extension from scratch.

### 1. Scaffold the extension

```bash
php bin/pulsar make:extension notifications --vendor=myapp
```

### 2. Edit the manifest

```json
{
  "name": "myapp/notifications",
  "version": "1.0.0",
  "description": "In-app notification system",
  "extension_class": "Myapp\\Notifications\\NotificationsExtension",
  "pulsar": {
    "min_version": "1.0.0"
  },
  "provides": {
    "services": ["NotificationService", "NotificationRepository"],
    "routes": true,
    "commands": ["SendNotificationCommand"]
  }
}
```

### 3. Implement the extension class

```php
<?php

declare(strict_types=1);

namespace Myapp\Notifications;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

final class NotificationsExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'myapp/notifications';
    }

    public function register(ContainerInterface $container): void
    {
        // Direct bindings (or use a service provider)
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $router->get('/notifications', [NotificationController::class, 'index'], 'notifications.index');
        $router->post('/notifications/{id}/read', [NotificationController::class, 'markRead'], 'notifications.read');
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            NotificationsServiceProvider::class,
        ];
    }
}
```

### 4. Add the service provider

```php
<?php

declare(strict_types=1);

namespace Myapp\Notifications;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;

final class NotificationsServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(NotificationRepository::class, NotificationRepository::class);
        $container->bind(NotificationService::class, NotificationService::class);
    }

    public function provides(): array
    {
        return [
            NotificationRepository::class,
            NotificationService::class,
        ];
    }
}
```

### 5. Register the extension

There is nothing to register. Discovery scans a **fixed** location: the
`extensions/` directory beside `config/`. Put the extension there and it is
found.

There is no `extensions.paths` setting. `ExtensionDiscovery::discover()` derives
the project root from the config path, appends `extensions`, and loads every
`pulsar.json` manifest under it; a `paths` key in `config/app.php` is read by
nothing and adds no directory. The scaffolded `public/index.php` scans two fixed
roots - `vendor/pulsar/framework/extensions` for the bundled ones and
`<project>/extensions` for yours - and to load an extension from anywhere else
you call `loadFromPaths()` yourself, as shown under
[Programmatic discovery](#programmatic-discovery).

What `config/app.php` **does** control is which of the discovered extensions
boot:

```php
return [
    'extensions' => [
        // Exclusive allowlist. Present: only these load. Absent: everything
        // discovered loads, except off-by-default products.
        'enabled' => [
            'myapp/notifications',
        ],

        // Additive opt-in for bundled products, which stay off until listed
        // here whatever 'enabled' says.
        'enabled_products' => [],
    ],
];
```

Names are the manifest's `name` field. Non-string entries are dropped rather
than trusted, so a malformed list cannot smuggle a non-name into either control.
Both keys are optional; omitting `enabled` means "load what you find", and
omitting `enabled_products` means "load no products".

### Programmatic discovery

To discover and load extensions programmatically (e.g. in a custom entry point):

```php
use Pulsar\Extensibility\ExtensionBootstrap;

// Static factory creates a bootstrap with a default ExtensionLoader
$extensions = ExtensionBootstrap::create();

// Discover extensions from one or more directories
$extensions->loadFromPaths([
    __DIR__ . '/../extensions',      // Scans for pulsar.json in subdirectories
    __DIR__ . '/../vendor/acme/ext', // Individual extension directory
]);

// Check for any load warnings (skipped extensions, version mismatches)
foreach ($extensions->getLoadWarnings() as $warning) {
    error_log($warning);
}

// Pass to Kernel constructor
$kernel = new Kernel(extensionBootstrap: $extensions);
```

`loadFromPaths()` performs four steps: manifest discovery, version validation, class validation, and dependency-order sorting. Extensions that fail validation are skipped with a warning (logged via the injected `LoggerInterface`).

## Debugging extensions

Use the CLI to inspect extensions:

```bash
php bin/pulsar diagnostics        # Shows loaded extension count
php bin/pulsar show:container     # Shows all container bindings (including extension services)
php bin/pulsar show:routes        # Shows all routes (including extension routes)
```

## Registering routes

Extensions register their routes during `boot()`. Routes are first-registered-wins
and application routes register before extensions, so an extension route on the
same method and path as a project route is treated as a collision: it is excluded
from matching, logged as a warning in production, and fails the boot in debug mode
(see [ADR-0034](adr/0034-route-registration-precedence.md)).

Because of this, an extension must not claim a bare top-level path
unconditionally. Make routes **opt-in and prefix-configurable** so an application
that owns a path can disable or relocate them. The `pulsar/booking` extension is
the reference: `config/booking.php` exposes `routes_enabled`, `route_prefix`, and
`admin_route_prefix`, and `boot()` returns early when routes are disabled and
prefixes every path with the configured value.

```php
public function boot(ContainerInterface $container, RouterInterface $router): void
{
    $config = $container->get(BookingConfig::class);

    if (!$config->routesEnabled) {
        return;
    }

    $router->get($config->routePrefix, [BookingController::class, 'form'], 'booking.form');
    // ...
}
```

## Error handling

The extension system uses specific exception types:

- `ManifestException`: Invalid or missing manifest files, missing required fields, invalid version format, unparseable JSON.
- `DependencyException`: Circular dependencies, missing required extensions.
- `ExtensionException`: General extension loading and lifecycle errors.

All exceptions use static factory methods for clear, contextual error messages.

## Trust tier enforcement

### Where an extension's tier comes from

Before reading what a tier permits, be clear about what confers one. **A tier is granted by the host application, never by the extension.**

- `pulsar.json` may declare `trust_tier`. That is a **request**, and it is unauthenticated: the manifest sits in the same directory as the code it describes, and **Pulsar verifies no extension signature anywhere** — there is no publisher key, no trust store, and no verifier on any load or install path. Treat a `trust_tier` you read out of a manifest exactly as you would treat a claim in a README.
- `config/extensions.php` — the host's file, not the extension's — maps extension names to the tier the operator is willing to grant.
- The effective tier used at boot is `min(requested, granted)`, and an extension the host has not listed gets `Community` no matter what its manifest says. So the manifest can only ever **lower** an extension's privileges, which is why parsing it unverified is safe.

The practical consequence for an operator: everything above Community in your deployment is there because you typed a name into `config/extensions.php`. Review that file the way you would review a list of packages you have decided to trust, because that is exactly what it is.

### Framework-enforced boundaries

The extension system enforces capability restrictions at the PHP runtime level through scoped proxies:

| Resource                     | Enforcement mechanism  | Capability required                         |
| ---------------------------- | ---------------------- | ------------------------------------------- |
| Container services           | `ScopedContainerProxy` | Per-service (see `ServiceRestrictionMap`)   |
| Route registration           | `ScopedRouterProxy`    | `RouteRegister` / `RouteRegisterGlobal`     |
| `Config\Environment` service | `ScopedContainerProxy` | `EnvRead` (via the service restriction map) |

These proxies intercept calls and check the extension's effective trust tier against the `CapabilityPolicy`. Violations throw `CapabilityDeniedException`.

The container is a genuine chokepoint: an extension cannot obtain a restricted service without going through it. A capability whose subject is reachable by any other means is listed below instead, because a gate the constrained code can walk around is not enforcement.

#### What the scoped container will not give you, at any tier below Core

The proxy checks the service ID you ask for **and the object that comes back**, because an
ID is a name your composition root chose and says nothing about what is behind it.

- **A container or a router is exchanged for your own scoped one.** Resolving
  `ContainerInterface` or `RouterInterface` by name is refused outright with an explanatory
  error; anywhere the framework hands you a container without your asking — a factory
  closure you registered, a decorator closure, a deferred provider's `register()` — you get
  the same scoped proxy you already had, not the real container.
- **The configuration registry is narrowed to your own sections.** One
  `ExtensionConfigRegistry` holds every installed extension's `config/<name>.php`, and a
  `payments` section carries a webhook secret in a real deployment. Resolving it below Core
  returns a view holding only the sections **your** extension ships — the section name is
  your config file's basename with `-` normalised to `_`, so `config/my-thing.php` is
  `my_thing`. An operator's override of one of your sections still reaches you; another
  extension's section answers `has()` false and `section()` `[]`. An extension that ships no
  `config/` directory receives an empty registry, which is what every `fromArray()` already
  reads as "not configured".
- **Anything else that dispenses services is refused.** The kernel, the
  `ExtensionBootstrap`, the `ExtensionRegistry`, a wiring, the config manager, the
  middleware pipeline and registry, the argument-resolver registry.
- **`$container->call()` resolves through your scope.** Type-hinting a parameter is asking
  for a service by another name, and it is answered by the same rules `get()` uses.
- **Binding a class NAME builds it in your scope.** `bind('id', Mine::class)` would let the
  _container_ choose that constructor's arguments, so the binding becomes a factory that
  builds `Mine` here instead: each parameter is resolved through your own `get()`, and so is
  each of ITS parameters, with no depth limit. The same applies to your service providers,
  your route handlers, your route middleware, your model resolvers and your CLI commands —
  everything the framework constructs from a class name you supplied. This replaced an
  earlier check that predicted what the container would put in a constructor: a prediction
  can be walked around by adding one collaborator or by declaring the class after binding
  it, and both were.
- **`has()` answers for your scope, not for the host.** It is true when you can actually
  resolve the id and false when the host bound something your tier may not have, so
  `if ($c->has($x)) { $c->get($x); }` does what PSR-11 says it does. A service you can see
  in the host's composition root may therefore read as "not configured" to you; that is a
  missing capability grant, not a bug to code around.
- **Another extension's PUBLISHED types are resolvable; the rest of it is not.** A type is
  reachable from your scope when the extension that ships it names it in its own
  manifest's `provides.services`. You declare nothing to consume one — the decision is the
  provider's, and it is anchored to the file the type is declared in, so a manifest cannot
  publish a class its extension does not ship. Guard the call with `has()` so your
  extension still boots when the other one is not installed.

If you need the container inside something you bind, register a **factory closure**:
`bind('id', fn(ContainerInterface $c) => new Mine($c))`. A closure registered through the
scoped container is invoked with that scoped container.

### Operationally-enforced boundaries

Some capabilities cannot be enforced at the PHP level because PHP has no native process sandboxing, and because the underlying operation is a global function any loaded code may call. These require operational controls in production:

| Capability        | Why PHP cannot enforce it                                          | Recommended operational control                                                                                                                     |
| ----------------- | ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `FilesystemWrite` | PHP can write to any writable path                                 | Use `open_basedir` in `php.ini` to restrict paths                                                                                                   |
| `NetworkEgress`   | PHP can open sockets to any host                                   | Use firewall rules or network policies (Kubernetes NetworkPolicy)                                                                                   |
| `ProcessExec`     | `exec()`/`proc_open()` are global                                  | Use `disable_functions` in `php.ini`                                                                                                                |
| `DatabaseRaw`     | DSN credentials are available                                      | Use separate database users with restricted privileges                                                                                              |
| `EnvRead`         | `getenv()`, `$_ENV` and `$_SERVER` are readable by any loaded code | Keep secrets out of the environment of a process that loads untrusted extensions — use a secret store, and rotate anything the environment has held |

`EnvRead` gates the `Pulsar\Config\Environment` **service** at the container, which is worth having: it stops an honest extension from reaching the framework's environment reader. It does not and cannot stop `getenv()`. Pulsar shipped a `ScopedEnvironmentProxy` that filtered sensitive variable names by tier; it was reachable from nothing and, had it been wired, would have been bypassed by one call to a global function, so it was removed rather than left in place implying a boundary that does not exist.

**Production recommendation**: Set `disable_functions = exec,passthru,shell_exec,system,proc_open,popen` in `php.ini` for application workers. Only CLI console workers that need process execution should have these functions enabled.

### Where each capability is enforced

A capability that gates nothing is a claim, and this page is cited for SOX, HIPAA and
PCI-DSS controls. Three capabilities — `MiddlewareRegister`, `CommandRegister` and
`AuditWrite` — were in that state: declared in `ExtensionCapability`, granted by
`CapabilityPolicy`, printed in the tier table, and consulted by no code anywhere in
`src/`. They now have enforcement sites, each pinned by a test that fails if the check is
removed:

| Capability           | Enforcement site                                                                                                                      | Test that pins it                                                                                                                                             |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `MiddlewareRegister` | `ServiceRestrictionMap` prices `MiddlewarePipelineInterface` and `MiddlewareRegistry`, the two ids the Kernel binds (`:149-150`)      | `ServiceRestrictionMapTest::globalMiddlewareCostsMiddlewareRegister`, `SandboxEscapeRoutesTest::middlewareRegisterNowGatesTheGlobalPipeline`                  |
| `CommandRegister`    | `ExtensionBootstrap::buildCommand()` charges the declaring extension's tier before it constructs the command (`:682-690`)             | `SandboxEscapeRoutesTest::aCommandFromATierWithoutCommandRegisterIsRefused`, `BundledExtensionContractTest::noCommandTheManifestPromisesIsDeniedByItsOwnTier` |
| `AuditWrite`         | `ServiceRestrictionMap` prices `AuditLoggerInterface` and `AuditLogger` (`:104-105`); both were on the safe list until this was fixed | `ServiceRestrictionMapTest::theAuditLoggerCostsAuditWriteRatherThanBeingSafeListed`, `SandboxEscapeRoutesTest::auditWriteNowGatesTheAuditLogger`              |

The audit sink and the audit logger are priced separately, because they are different
powers: draining or reconfiguring the sink is `AuditSinkAccess` (Core and Verified),
appending an entry is `AuditWrite` (down to Community, never Untrusted).

`ProcessExec` still prices nothing, and that is a statement rather than an omission: the
framework exposes no process-execution service to price — `ServeCommand` calls `proc_open()`
directly — and the capability is granted to Core alone, which bypasses the restriction map
entirely. The map used to name `Pulsar\Process\ProcessManagerInterface`, a type that has
never existed here, which made an inert grant look enforced.
`ServiceRestrictionMapTest::processExecPricesNothingBecauseThereIsNoProcessService` holds
the map to that.

An earlier revision of this section said all three were "never checked" and cited
`tests/Integration/Extensibility/SandboxOpenGapsTest.php` as scanning `src/` to keep the
claim honest. That test does no such scan: it executes the escapes that remain **open** —
reflection reaching the unscoped container, an Untrusted extension reading the host route
table, and forged event dispatch — and has never had anything to do with these three
capabilities. Those gaps are described under [What the sandbox is not](#what-the-sandbox-is-not)
and in ADR-0023.

### What the sandbox is not

It is not a memory boundary. An extension is PHP running in your process, so
`ReflectionProperty` reads `ScopedContainerProxy`'s private `$inner` and returns the real
container in one line, at any tier. PHP offers no way to withhold that from in-process
code, and ADR-0023 weighed process isolation and rejected it on cost.

What the tier system buys, then, is that reach is **explicit and auditable** rather than
ambient: an extension that takes the documented route is confined, and one that does not
has to do something that looks exactly like what it is. Installing an extension is still a
decision to run someone else's code. Tier it accordingly, and read
[ADR-0023](adr/0023-extension-trust-tiers.md) — "What this does not stop" — before relying
on it for a compliance control.

### Trust tier summary

**[ADR-0023](adr/0023-extension-trust-tiers.md) owns the capability model.** Its matrix is
the per-capability statement of what each tier is granted, and `CapabilityPolicy::defaults()`
is the code that grants it. This table is a second view of the same facts for a different
question — not "which capabilities" but "what can this extension actually reach" — so every
column names the capability it renders, and `TrustTierDocumentationTest` checks each cell
against the policy. It cannot drift from the ADR without failing the build.

Every cell means "may resolve the framework service that does this", because the container
is where the check happens. None of them means the extension is prevented from doing it by
other means — see the operational table above.

| Tier      | Container | Routes   | `EnvRead` | `FilesystemWrite` | `NetworkEgress` | `ProcessExec` |
| --------- | --------- | -------- | --------- | ----------------- | --------------- | ------------- |
| Core      | Full      | Global   | yes       | yes               | yes             | yes           |
| Verified  | Most      | Global   | yes       | yes               | yes             | no            |
| Community | Limited   | Prefixed | no        | no                | no              | no            |
| Untrusted | Read-only | No       | no        | no                | no              | no            |

The last four columns are the capabilities gating the `Environment` service, the
`Filesystem` service, the HTTP client and the process manager. The first two are words
rather than yes/no because the answer is graded, and each word is one capability:

- **Container** — `Full` may override an existing binding (`ContainerWrite`); `Most` may
  decorate one (`ServiceDecorate`); `Limited` may register its own (`ServiceRegister`);
  `Read-only` may only resolve (`ContainerRead`).
- **Routes** — `Global` may register any path (`RouteRegisterGlobal`); `Prefixed` may
  register under its own prefix (`RouteRegister`); `No` may not register at all.

The host can grant a specific capability to a specific extension with `additional_capabilities` in `config/extensions.php` without moving it to a higher tier.

**This reaches container capabilities only.** `ScopedContainerProxy` consults the per-extension grants; `ScopedRouterProxy` is constructed with the tier and the policy alone and never sees them, so `RouteRegister` and `RouteRegisterGlobal` cannot be granted this way — a Community extension given `RouteRegisterGlobal` in `additional_capabilities` still cannot call `model()` or register an unprefixed route. The failure is a refusal rather than an unintended grant, so it is safe, but it is silent: raise the extension's tier if it genuinely needs global route registration.

**A name the framework does not have is a configuration error.** A `tier` that is not one of the four, or an `additional_capabilities` entry that does not match a capability name exactly, refuses the boot and says which names exist. Both used to be swallowed — the tier resolved to `community`, the capability was filtered out of the list — which fails in the safe direction and is why it went unnoticed: `config/extensions.php` is the record of what each extension was granted, and it could read as a grant that had been made while the runtime made none.

## Admin theme integration

The Admin extension ships its own layout and styles. Projects can customize the admin panel appearance without forking the extension.

### Theme configuration

Set the theme key in `config/admin.php`:

```php
return [
    'theme' => 'dark',  // Built-in themes: 'light' (default), 'dark'
];
```

### CSS custom properties

The admin panel uses CSS custom properties from `resources/ui/css/tokens.css` for colors, spacing, typography, and other design tokens. Override any token in your project stylesheet:

```css
:root {
  --pulsar-color-primary: #1a73e8;
  --pulsar-color-surface: #fafafa;
  --pulsar-font-family-heading: 'Montserrat', sans-serif;
}
```

### Load order

Extension CSS loads after the base `pulsar-ui.css` stylesheet. This means extension styles can rely on base tokens, and project-level overrides applied after extensions take final precedence:

1. `resources/ui/css/base.css` (framework base, includes `@font-face` declarations)
2. `resources/ui/css/tokens.css` (design tokens)
3. Extension stylesheets (e.g., admin, analytics, studio)
4. Project stylesheets (highest specificity)

To inject a project stylesheet into the admin layout, register it in `config/admin.php`:

```php
return [
    'extra_css' => [
        '/css/admin-overrides.css',
    ],
];
```

## Concurrency constraints

Extensions must respect Pulsar's synchronous execution model. The framework provides no event loop, Fiber scheduler, or implicit parallelism.

### Rules

- **MUST NOT** create Fibers that escape their scope. A Fiber created by an extension must complete within the same request or command lifecycle that created it. Escaped Fibers break deterministic execution guarantees and can corrupt shared state.
- **MUST NOT** start background threads or use `parallel\Runtime`. Pulsar assumes single-threaded execution within each worker process.
- **MAY** use scoped Fibers for context isolation (the same pattern Studio uses internally), provided the Fiber is created, used, and completed within a well-defined scope with proper RAII cleanup.

### RAII cleanup

If an extension uses `ContextScope` or similar RAII guards within Fibers, it must ensure:

1. Every `enter()` call has a matching `close()` call.
2. The `close()` call happens from the same Fiber that called `enter()`.
3. Guards are closed before the Fiber completes, even in error paths.

For full details on Pulsar's concurrency model, Fiber usage, and guarantees, see [`docs/async-model.md`](async-model.md).
