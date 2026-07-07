# Contributing extensions

This guide covers everything you need to create, test, and publish a Pulsar extension. Extensions are the primary way to add functionality to a Pulsar application, and they use the same lifecycle hooks whether they are first-party or community-built.

## Creating a new extension

### Using the scaffold command

The fastest way to start is the built-in scaffold command:

```bash
php bin/pulsar make:extension my-feature --vendor=acme
```

This creates the following structure under `extensions/`:

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

### Manual setup

If you prefer to set up the directory yourself, create the following at minimum:

```
extensions/my-feature/
  pulsar.json
  src/
    MyFeatureExtension.php
```

The extension needs to be in a directory that Pulsar scans for extensions. By default, this is the `extensions/` directory in your project root. You can configure additional scan paths in `config/app.php` under `extensions.paths`.

## The manifest (pulsar.json)

Every extension must have a `pulsar.json` manifest at its root. This file declares the extension's identity, version, capabilities, and dependencies. The `ExtensionManifest` class at `src/Extensibility/ExtensionManifest.php` parses this file.

Here is a complete example:

```json
{
  "name": "acme/my-feature",
  "version": "1.0.0",
  "description": "A brief description of what this extension does",
  "trust_tier": "community",
  "extension_class": "Acme\\MyFeature\\MyFeatureExtension",
  "pulsar": {
    "min_version": "1.0.0"
  },
  "provides": {
    "services": ["MyFeatureService"],
    "routes": true,
    "commands": ["GenerateReportCommand"],
    "middleware": ["MyFeatureMiddleware"]
  },
  "requires": {
    "pulsar/orm": ">=1.0.0"
  }
}
```

### Required fields

| Field             | Description                                                     |
| ----------------- | --------------------------------------------------------------- |
| `name`            | Vendor-prefixed name (e.g., `acme/my-feature`). Must be unique. |
| `version`         | Semver version string (e.g., `1.0.0`, `0.3.0-beta`).            |
| `extension_class` | Fully qualified class name implementing `ExtensionInterface`.   |

### Optional fields

| Field         | Description                                                                                                                                                                    |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `description` | Human-readable summary of what the extension does.                                                                                                                             |
| `trust_tier`  | Requested trust level: `core`, `verified`, `community`, or `untrusted`. This is metadata only. The host application's `TrustedExtensionsConfig` determines the effective tier. |
| `pulsar`      | Framework version constraints (`min_version`, `max_version`).                                                                                                                  |
| `provides`    | Lists of services, routes, commands, and middleware the extension registers.                                                                                                   |
| `requires`    | Map of extension names to version constraints for dependencies.                                                                                                                |

The version format must follow semver (`MAJOR.MINOR.PATCH` with optional prerelease suffix). Invalid versions are rejected with `ManifestException::invalidVersion()`.

## Extension lifecycle

Extensions progress through a four-phase lifecycle managed by the `ExtensionBootstrap`. Understanding this lifecycle is important for knowing when your services are available and when you can safely resolve other extensions' services.

### Phase 1: Register

The framework calls `register()` on every extension. This is where you bind services to the DI container. At this point, no other extension has booted yet, so do not attempt to resolve services from other extensions.

### Phase 2: PreBoot (optional)

If your extension implements `PreBootExtensionInterface`, the framework calls `preBoot()` after all extensions have registered. At this point, every extension's services are in the container, so you can safely resolve cross-extension dependencies.

### Phase 3: Boot

The framework calls `boot()` on every extension. This is where you register routes, configure services, and perform initialization that depends on the container being fully wired.

### Phase 4: PostBoot (optional)

If your extension implements `PostBootExtensionInterface`, the framework calls `postBoot()` after all extensions have booted. Use this for wiring decorators, collectors, or observers that need to wrap the final state of services.

All four phases iterate over extensions in the same dependency-resolved order. If extension B requires extension A, then A's `register()` runs before B's `register()`, A's `boot()` runs before B's `boot()`, and so on.

### Lifecycle states

The `ExtensionLifecycle` enum tracks each extension's current state:

| State        | Meaning                                             |
| ------------ | --------------------------------------------------- |
| `Discovered` | Manifest found and parsed                           |
| `Validated`  | Manifest validated, version compatibility confirmed |
| `Registered` | Services bound to the container                     |
| `Booted`     | Routes registered, initialization complete          |
| `Failed`     | An error occurred at any point                      |

## Implementing ExtensionInterface

Your extension class must implement `Pulsar\Extensibility\ExtensionInterface`:

```php
<?php

declare(strict_types=1);

namespace Acme\MyFeature;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

final class MyFeatureExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'acme/my-feature';
    }

    public function register(ContainerInterface $container): void
    {
        // Bind services here, or leave empty if using providers
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $router->get('/my-feature', [MyFeatureController::class, 'index'], 'my-feature.index');
        $router->post('/my-feature', [MyFeatureController::class, 'store'], 'my-feature.store');
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

The `name()` return value must match the `name` field in `pulsar.json`.

## Service providers and dependency injection

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
        $container->singleton(
            MyFeatureRepository::class,
            MyFeatureRepository::class,
        );
        $container->bind(
            MyFeatureService::class,
            MyFeatureService::class,
        );
    }

    public function provides(): array
    {
        return [
            MyFeatureRepository::class,
            MyFeatureService::class,
        ];
    }
}
```

Use `singleton()` for services that should be instantiated once and reused (repositories, configuration objects). Use `bind()` for services that should be freshly created on each resolution.

All services are resolved via constructor injection. The container inspects your constructor's type hints and auto-wires dependencies. There is no service locator pattern, no `$container->get()` calls from within your services.

## Registering routes

Register routes in your extension's `boot()` method using the `RouterInterface`:

```php
public function boot(ContainerInterface $container, RouterInterface $router): void
{
    $router->group('/api/my-feature', function (RouterInterface $r): void {
        $r->get('/', [MyFeatureController::class, 'index'], 'my-feature.index');
        $r->get('/{id}', [MyFeatureController::class, 'show'], 'my-feature.show');
        $r->post('/', [MyFeatureController::class, 'store'], 'my-feature.store');
        $r->put('/{id}', [MyFeatureController::class, 'update'], 'my-feature.update');
        $r->delete('/{id}', [MyFeatureController::class, 'destroy'], 'my-feature.destroy');
    });
}
```

The `group()` method applies a common URL prefix to all routes registered inside the callback. Route names (the third argument) should be unique across the application, so prefix them with your extension name.

## Registering commands

List your console commands in the `provides.commands` field of `pulsar.json`. Commands implement `Pulsar\Console\CommandInterface` and are discovered during the boot phase:

```json
{
  "provides": {
    "commands": ["Acme\\MyFeature\\Command\\GenerateReportCommand"]
  }
}
```

## Registering middleware

Extensions can provide both global and route-level middleware.

For global middleware, resolve `MiddlewarePipelineInterface` during `postBoot()`:

```php
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Http\Middleware\MiddlewarePipelineInterface;

final class MyFeatureExtension implements ExtensionInterface, PostBootExtensionInterface
{
    public function postBoot(ContainerInterface $container): void
    {
        if ($container->has(MiddlewarePipelineInterface::class)) {
            $pipeline = $container->get(MiddlewarePipelineInterface::class);
            $pipeline->pipe(new MyFeatureMiddleware());
        }
    }
}
```

List middleware class names in the `provides.middleware` manifest field for discoverability.

## Registering configuration

Extensions can provide typed configuration by implementing `ConfigLoaderInterface`:

```php
use Pulsar\Config\ConfigLoaderInterface;
use Pulsar\Config\Environment;

final class MyFeatureConfigLoader implements ConfigLoaderInterface
{
    public function configClass(): string
    {
        return MyFeatureConfig::class;
    }

    public function load(array $data, Environment $environment): object
    {
        return MyFeatureConfig::fromArray($data);
    }
}
```

Configuration DTOs should be readonly classes with a static `fromArray()` factory method, following the same pattern as the framework's built-in configs.

## Testing conventions

### Directory structure

Place your tests alongside your extension source:

```
extensions/my-feature/
  tests/
    Unit/
      MyFeatureServiceTest.php
      Controller/
        MyFeatureControllerTest.php
    Integration/
      MyFeatureIntegrationTest.php
```

### Running tests

Extension tests run as part of the main test suite. PHPUnit's configuration at `tools/php/phpunit.xml` includes the `extensions/` directory. You can run tests for a single extension:

```bash
vendor/bin/phpunit -c tools/php/phpunit.xml --filter MyFeature
```

### Test guidelines

- Use `createStub()` when you do not need to assert that a method was called. Reserve `createMock()` for cases where you need `expects()`.
- Test behavior, not implementation. A test that asserts "calling `process()` with invalid input throws `ValidationException`" is better than one that asserts "the constructor sets `$this->validator`."
- Include edge cases and error paths. Test what happens with empty input, null values, boundary conditions, and malformed data.
- Use data providers for repetitive test cases with varying inputs.
- Aim for high coverage, but prioritize meaningful assertions over hitting a coverage percentage.

## Publishing to the marketplace

Once your extension is stable and tested, you can publish it to the Pulsar marketplace.

### Preparation

1. Ensure your `pulsar.json` is complete with all fields, especially `description` and `pulsar.min_version`.
2. Include a `README.md` in your extension root with installation and usage instructions.
3. Add a `LICENSE` file.
4. Run the full quality gate against your extension code: static analysis, tests, and code style.
5. Tag a semver release in your version control.

### Publishing

Publish your extension as a Composer package. Users install it with:

```bash
composer require acme/my-feature
```

The extension is discovered automatically if its directory contains a `pulsar.json` manifest and is located under one of the configured extension scan paths.

## Trust tiers

Extensions operate under a trust tier system that controls their access to framework internals. The tiers, from most to least privileged:

| Tier        | Who uses it                      | Container access | Route registration |
| ----------- | -------------------------------- | ---------------- | ------------------ |
| `Core`      | First-party framework extensions | Full             | Global             |
| `Verified`  | Audited third-party extensions   | Most services    | Global             |
| `Community` | Unaudited third-party extensions | Limited          | Prefixed only      |
| `Untrusted` | Experimental or sandboxed        | Read-only        | None               |

Your `pulsar.json` declares a requested trust tier, but the host application's configuration determines the effective tier. The effective tier is always the lower of the requested and allowed tiers.

For most community extensions, the `Community` tier is appropriate. Extensions that need access to sensitive services (cryptographic keys, raw database connections, audit sinks) should document this requirement clearly and explain why the elevated access is necessary. The host application operator makes the final decision.

See the [extension trust tiers documentation](../extensions.md) and [ADR-0023](../adr/0023-extension-trust-tiers.md) for the full capability model.

## Common pitfalls

- **Resolving services in `register()`.** During the registration phase, other extensions have not registered their services yet. If you need to resolve cross-extension services, use `preBoot()` or `boot()`.
- **Creating escaped Fibers.** Pulsar uses a synchronous execution model. Any Fiber your extension creates must complete within the same request or command lifecycle. Fibers that escape their scope break deterministic execution guarantees.
- **Shadowing framework routes.** Community-tier extensions can only register routes under a prefix. Attempting to register routes at `/admin` or `/_studio` from a community extension will be denied by the `ScopedRouterProxy`.
- **Importing from `\Internal\` namespaces.** Anything under an `\Internal\` namespace is module-private. Cross-module imports from internal namespaces will fail boundary enforcement checks (`composer boundary:check`). Wire your dependencies through the composition root instead.
