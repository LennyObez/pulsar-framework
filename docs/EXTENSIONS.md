# Extension System Guide

Pulsar's extension system provides modular, manifest-driven extensibility with a deterministic two-phase lifecycle. Extensions are the primary mechanism for adding functionality to a Pulsar application -- including routing, DI bindings, console commands, middleware, and migrations.

This is an original design. It is not a clone of Laravel service providers or Symfony bundles. The system enforces explicit capability declaration, strong versioning, compatibility checks, and a "zero entropy" principle: there is one obvious way to wire an extension.

## Architecture Overview

### Core Concepts

- **Manifest-driven**: Every extension declares its capabilities in a `pulsar.json` file.
- **Two-phase lifecycle**: Extensions progress through Register (bind services) and Boot (register routes, initialize).
- **Deterministic boot pipeline**: Extensions are discovered, validated, dependency-sorted, and booted in a predictable order.
- **No privileged access**: Built-in framework features use the same extension API as third-party code.

### Lifecycle States

Extensions progress through these states during the boot process, defined by the `ExtensionLifecycle` enum:

| State        | Description                                             |
| ------------ | ------------------------------------------------------- |
| `Discovered` | Extension manifest found and parsed                     |
| `Validated`  | Manifest validated, version compatibility confirmed     |
| `Registered` | Services bound to the DI container                      |
| `Booted`     | Routes registered, initialization complete              |
| `Failed`     | An error occurred (can happen from any preceding state) |

State transitions are enforced: an extension can only register from the `Validated` state and can only boot from the `Registered` state.

## Creating an Extension

### Step 1: Extension Directory

Create a directory under `extensions/` (or use the scaffold command):

```bash
php bin/pulsar scaffold:extension my-feature --vendor=acme
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

### Step 2: The Manifest (pulsar.json)

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

#### Manifest Fields

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

- `services` -- List of service class names registered in the container.
- `routes` -- Boolean indicating whether the extension registers routes.
- `commands` -- List of console command class names.
- `middleware` -- List of middleware class names.

### Step 3: Implement ExtensionInterface

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

#### Interface Methods

| Method        | Phase    | Purpose                                                          |
| ------------- | -------- | ---------------------------------------------------------------- |
| `name()`      | --       | Returns the unique extension name (must match `pulsar.json`)     |
| `register()`  | Register | Bind services, interfaces, and factories to the DI container     |
| `boot()`      | Boot     | Register routes, configure services, perform initialization      |
| `providers()` | Register | Return class names of `ServiceProviderInterface` implementations |

The `register()` method is called first for all extensions before any `boot()` method runs. This guarantees that all services are available during the boot phase.

## Service Providers

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

## Extension Capabilities

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

### DI Bindings

Register services in `register()` or via service providers:

```php
public function register(ContainerInterface $container): void
{
    $container->singleton(CacheInterface::class, fn() => new RedisCache());
    $container->bind(WidgetRepository::class, WidgetRepository::class);
}
```

### Console Commands

Extensions can provide console commands by listing them in the `provides.commands` manifest field. Commands implement `Pulsar\Console\CommandInterface` and are registered during the boot phase.

### Middleware

Extensions can provide middleware by listing class names in the `provides.middleware` manifest field. Middleware classes implement `Pulsar\Http\Middleware\MiddlewareInterface`.

### Migrations

Extensions can bundle database migrations. Migration files follow the naming convention `{YYYYMMDDHHmmss}_{name}.php` and implement `Pulsar\Database\Migration\MigrationInterface`.

## Versioning and Compatibility

### Framework Version Constraints

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

### Extension Dependencies

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

### Semver Rules

- Extension versions must follow semver: `MAJOR.MINOR.PATCH` with optional prerelease suffix.
- The manifest parser validates version format and rejects invalid versions with `ManifestException::invalidVersion()`.
- Dependency version constraints follow standard semver range notation.

## Example: Complete Extension Walkthrough

This walkthrough builds a "Notifications" extension from scratch.

### 1. Scaffold the Extension

```bash
php bin/pulsar scaffold:extension notifications --vendor=myapp
```

### 2. Edit the Manifest

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

### 3. Implement the Extension Class

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

### 4. Add the Service Provider

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

### 5. Register the Extension

Ensure the extension path is included in your `config/app.php`:

```php
return [
    'extensions' => [
        'paths' => [
            __DIR__ . '/../extensions',
        ],
    ],
];
```

The extension loader discovers all `pulsar.json` manifests under the configured paths automatically.

## Debugging Extensions

Use the CLI to inspect extensions:

```bash
php bin/pulsar diagnostics        # Shows loaded extension count
php bin/pulsar show:container     # Shows all container bindings (including extension services)
php bin/pulsar show:routes        # Shows all routes (including extension routes)
```

## Error Handling

The extension system uses specific exception types:

- `ManifestException` -- Invalid or missing manifest files, missing required fields, invalid version format, unparseable JSON.
- `DependencyException` -- Circular dependencies, missing required extensions.
- `ExtensionException` -- General extension loading and lifecycle errors.

All exceptions use static factory methods for clear, contextual error messages.

## Concurrency Constraints

Extensions must respect Pulsar's synchronous execution model. The framework provides no event loop, Fiber scheduler, or implicit parallelism.

### Rules

- **MUST NOT** create Fibers that escape their scope. A Fiber created by an extension must complete within the same request or command lifecycle that created it. Escaped Fibers break deterministic execution guarantees and can corrupt shared state.
- **MUST NOT** start background threads or use `parallel\Runtime`. Pulsar assumes single-threaded execution within each worker process.
- **MAY** use scoped Fibers for context isolation (the same pattern Studio uses internally), provided the Fiber is created, used, and completed within a well-defined scope with proper RAII cleanup.

### RAII Cleanup

If an extension uses `ContextScope` or similar RAII guards within Fibers, it must ensure:

1. Every `enter()` call has a matching `close()` call.
2. The `close()` call happens from the same Fiber that called `enter()`.
3. Guards are closed before the Fiber completes, even in error paths.

For full details on Pulsar's concurrency model, Fiber usage, and guarantees, see [`docs/ASYNC_MODEL.md`](ASYNC_MODEL.md).
