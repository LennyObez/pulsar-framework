# Bootstrap

How to wire the Pulsar kernel in an HTTP entry point.

## Minimal `public/index.php`

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Pulsar\Config\ConfigManager;
use Pulsar\Config\Environment;
use Pulsar\Core\Kernel;
use Pulsar\Extensibility\ExtensionBootstrap;

// 1. Load environment
$env = Environment::load(__DIR__ . '/../.env');

// 2. Create config manager pointing at config/ directory
$configManager = new ConfigManager(
    configPath: __DIR__ . '/../config',
    environment: $env,
);

// 3. Discover extensions
$extensions = ExtensionBootstrap::create();
$extensions->loadFromPaths([__DIR__ . '/../extensions']);

// 4. Create and run the kernel
$kernel = new Kernel(
    configManager: $configManager,
    extensionBootstrap: $extensions,
);

$kernel->run();
```

`Kernel::run()` calls `ServerRequest::fromGlobals()`, pipes the request through the middleware pipeline, dispatches it to the matched route handler, and emits the response.

## What happens during `Kernel::boot()`

The kernel boots automatically on the first `handle()` or `run()` call. The boot pipeline runs in this order:

1. **Cache check** -- attempt to load cached config, routes, and container hints from `var/cache/`
2. **Build artifact verification** -- in production, assert that `build-manifest.json` exists and passes integrity checks
3. **Config loading** -- `ConfigManager::load()` reads PHP config files from `config/`
4. **Wiring phase** -- 30+ wiring classes register services into the container (config, logging, tracing, security, metrics, auth, database, cache, mail, views, etc.)
5. **Project route files** -- loads `routes/web.php` and `routes/api.php` if they exist (skipped when routes are cached)
6. **Extension register** -- all extensions register their service providers
7. **Compiler passes** -- auto-tagging, lifetime validation, decorator validation
8. **Extension boot** -- preBoot, boot, postBoot phases in dependency order

## Without extensions

If you have no extensions:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Pulsar\Config\ConfigManager;
use Pulsar\Config\Environment;
use Pulsar\Core\Kernel;

$env = Environment::load(__DIR__ . '/../.env');
$configManager = new ConfigManager(__DIR__ . '/../config', $env);

$kernel = new Kernel(configManager: $configManager);
$kernel->run();
```

## Without config (minimal)

For quick prototypes with no config files:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;

$kernel = new Kernel();

$kernel->router()->add(new Route(
    methods: [Method::GET],
    path: '/',
    handler: fn() => Response::html('<h1>Hello, Pulsar!</h1>'),
));

$kernel->run();
```

## Custom route registration

Projects register routes by creating PHP files in the `routes/` directory. The kernel automatically loads these during boot:

| File             | Purpose                     |
| ---------------- | --------------------------- |
| `routes/web.php` | Browser-facing routes       |
| `routes/api.php` | API routes (JSON endpoints) |

Inside route files, the `$router` and `$container` variables are available:

```php
// routes/web.php
<?php

use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

$router->get('/', fn() => Response::html('<h1>Welcome</h1>'), 'home');

$router->get('/about', fn() => Response::html('<h1>About</h1>'), 'about');

$router->get('/users/{id}', function (ServerRequest $request, string $id) {
    return Response::json(['id' => $id]);
}, 'users.show');
```

Project routes load before extension routes, so extensions can layer routes on top of or alongside project-defined routes. CMS routes, forum routes, and other extension routes are registered during the extension boot phase.

When routes are cached (via `pulsar routes:cache`), route files are not loaded. The cached routes are used directly instead.

## Adding global middleware

```php
$kernel->addMiddleware(CorsMiddleware::class);
$kernel->addMiddleware(new RateLimitMiddleware($limiter));
```

Global middleware wraps every request. Route-specific middleware is defined per-route.

## Persistent runtimes (Swoole, RoadRunner)

For persistent runtimes, call `Kernel::handle()` per request instead of `run()`:

```php
$kernel = new Kernel(configManager: $configManager, extensionBootstrap: $extensions);
$kernel->boot();

// In the request loop:
$response = $kernel->handle($request);
$emitter->emit($response);
$kernel->terminate($request, $response);
```

`terminate()` dispatches the `TerminateEvent` and runs terminable middleware. This is critical for cleanup in long-lived processes.
