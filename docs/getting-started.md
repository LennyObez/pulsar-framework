# Getting started

This guide walks you through installing Pulsar, creating a project, and building your first routes and controllers. By the end you will have a working application serving HTTP responses.

## Prerequisites

You need two things installed on your system:

- **PHP 8.5.1 or later**. `composer.json` requires `>=8.5.1`, so 8.5.0 will not
  install. Run `php -v` to check.
- **Composer 2.2 or later**. `composer.json` declares no explicit minimum; 2.2 is
  where `config.allow-plugins` arrived, which this repository's own tooling uses.
  Run `composer --version` to check.

Pulsar needs more PHP extensions than a minimal build ships, and Composer refuses
to install without every one of them. `composer.json` is the authority, and this
is what it requires today:

```
ctype  curl  dom  exif  fileinfo  gd  libxml  mbstring
openssl  pdo  simplexml  sodium  zip  zlib
```

Check yours in one line:

```bash
php -r 'foreach (["ctype","curl","dom","exif","fileinfo","gd","libxml","mbstring","openssl","pdo","simplexml","sodium","zip","zlib"] as $e) { if (!extension_loaded($e)) { echo "missing: $e\n"; } }'
```

The [installation guide](install.md#system-requirements) says what each one is
for and how to install the missing ones per platform.

Optional but recommended: `ext-opcache` for JIT compilation and preloading,
`ext-apcu` for in-memory config and route caching, `ext-intl` for ICU message
formatting, and `ext-redis` for Redis-backed sessions and cache.

## Installation

Add Pulsar to a project and let the CLI scaffold the application layout:

```bash
composer require pulsar/framework
php vendor/bin/pulsar init my-app
cd my-app
composer install
```

The binary is `vendor/bin/pulsar`, not `bin/pulsar`: `composer.json` declares
`"bin": ["bin/pulsar"]`, so Composer installs the proxy under `vendor/bin/` and a
consuming project has no `bin/` of its own.

`pulsar/framework` is the right package name, but it is not on Packagist yet —
publication waits for the `1.0.0` tag, so the first command above resolves nothing
today. Until then, point Composer at the repository first; the
[installation guide](install.md#while-pulsar-is-a-release-candidate) gives the two
forms (VCS and path) and what to remove once the tag lands.

There is no `composer create-project` starter template yet. Earlier revisions of
this page opened with `composer create-project pulsar/skeleton my-app`; no package
by that name has ever existed, so that command has never worked for anybody who
tried it. A skeleton repository is planned — it is tracked as
[issue #360](https://github.com/LennyObez/pulsar-framework/issues/360) — and this
section will name it when it exists. `pulsar init` is the supported way to start a
project today, and it writes the same layout described below.

## Project structure

A fresh Pulsar project looks like this:

```
my-app/
  app/
    Controllers/        # Your application controllers
    Middleware/          # Custom middleware classes
    Services/           # Business logic services
  config/
    app.php             # Application name, debug mode, extension paths
    database.php        # Database connections and drivers
    security.php        # Session, CSRF, rate limiting, audit settings
    observability.php   # Logging, metrics, tracing
  public/
    index.php           # Web entry point
  extensions/           # First-party and third-party extensions
  resources/
    views/              # Pulse template files (.pulse.php)
  tests/
    Unit/               # Unit tests
    Integration/        # Integration tests
  bin/pulsar            # CLI entry point
  .env.example          # Environment variable template
```

The `config/` directory contains PHP files that return arrays. These arrays are parsed into typed, readonly DTOs at boot time. The configuration is loaded once and is immutable at runtime.

## Verify your installation

Run the diagnostics command to confirm everything is working:

```bash
php bin/pulsar diagnostics
```

You should see output confirming the framework version, PHP version, and extension status. If any required PHP extensions are missing, the output will tell you which ones to install.

## Generate a master key

Pulsar uses a master key for encryption, HMAC-signed caches, and audit log chain integrity. Generate one now:

```bash
php bin/pulsar key:generate --write
```

This writes a `PULSAR_MASTER_KEY` to your `.env` file. Without it, crypto-dependent features are disabled, but the framework still boots and serves requests.

## Start the development server

Pulsar ships a persistent runtime server that boots the kernel once and handles requests without per-request overhead:

```bash
php bin/pulsar runtime:serve
```

This starts a server on `http://localhost:8080`. You can also use PHP's built-in server:

```bash
php -S localhost:8000 -t public
```

## Creating your first route

Extensions are discovered from the `extensions/` directory beside `config/` - a fixed location, not a configured one. There is no `extensions.paths` setting; see [Registering an extension](extensions.md#5-register-the-extension). For a fresh project, routes are registered in your application's main extension class, but you can also register them directly in the kernel's boot process.

The simplest way to add a route is through a controller. Let's build one.

## Creating a controller

Create a file at `app/Controllers/HomeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Pulsar\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HomeController
{
    public function index(ServerRequestInterface $request): Response
    {
        return Response::json([
            'message' => 'Hello from Pulsar',
            'version' => '1.0.0',
        ]);
    }

    public function greet(ServerRequestInterface $request, array $params = []): Response
    {
        $name = $params['name'] ?? 'World';

        return Response::json([
            'message' => "Hello, {$name}!",
        ]);
    }
}
```

Controllers are plain PHP classes. They receive a PSR-7 `ServerRequestInterface` and return a `Response`. Route parameters are passed as the second argument.

Pulsar resolves controller dependencies automatically through constructor injection. If your controller needs a service, add it as a constructor parameter:

```php
final readonly class HomeController
{
    public function __construct(
        private MyService $service,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $data = $this->service->fetchDashboardData();
        return Response::json($data);
    }
}
```

## Registering routes

Routes are registered using the `RouterInterface`. In an extension's `boot()` method:

```php
use Pulsar\Routing\RouterInterface;

public function boot(ContainerInterface $container, RouterInterface $router): void
{
    $router->get('/', [HomeController::class, 'index'], 'home');
    $router->get('/greet/{name}', [HomeController::class, 'greet'], 'greet');
}
```

The router supports all standard HTTP methods:

```php
$router->get('/items', [ItemController::class, 'index'], 'items.index');
$router->post('/items', [ItemController::class, 'store'], 'items.store');
$router->put('/items/{id}', [ItemController::class, 'update'], 'items.update');
$router->delete('/items/{id}', [ItemController::class, 'destroy'], 'items.destroy');
```

Group related routes under a common prefix:

```php
$router->group('/api', function (RouterInterface $r): void {
    $r->get('/users', [UserController::class, 'index'], 'api.users.index');
    $r->get('/users/{id}', [UserController::class, 'show'], 'api.users.show');
});
```

## Using the template engine

Pulsar ships a compile-to-PHP template engine called Pulse. Templates use the `.pulse.php` extension and support a clean directive syntax for control flow, inheritance, and output escaping.

### Setting up views

Create `config/view.php`:

```php
return [
    'template_paths' => [
        'resources/views',
    ],
    'cache_path' => 'var/cache/views',
    'auto_escape' => true,
];
```

### Creating a layout

Create `resources/views/layouts/app.pulse.php`:

```html
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <title>@yield('title', 'My App')</title>
  </head>
  <body>
    <main>@yield('content')</main>
  </body>
</html>
```

### Creating a page template

Create `resources/views/home/index.pulse.php`:

```html
@extends('layouts.app') @section('title') Welcome @endsection @section('content')
<h1>Hello, {{ $name }}!</h1>

@if($items)
<ul>
  @foreach($items as $item)
  <li>{{ $item }}</li>
  @endforeach
</ul>
@else
<p>No items yet.</p>
@endif @endsection
```

All `{{ }}` output is HTML-escaped by default. Use `{!! !!}` for trusted, pre-sanitized content only.

### Rendering from a controller

Inject `TemplateEngineInterface` into your controller:

```php
use Pulsar\View\Engine\TemplateEngineInterface;

final readonly class HomeController
{
    public function __construct(
        private TemplateEngineInterface $view,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $html = $this->view->render('home.index', [
            'name' => 'Pulsar',
            'items' => ['Routes', 'Controllers', 'Templates'],
        ]);

        return new Response(body: $html);
    }
}
```

Template names use dot notation. The name `home.index` resolves to `resources/views/home/index.pulse.php`.

## Running the dev server

With your routes and controllers in place, start the server:

```bash
php bin/pulsar runtime:serve
```

Visit `http://localhost:8080` to see your home page. Visit `http://localhost:8080/greet/Pulsar` to see the greeting endpoint.

To see all registered routes:

```bash
php bin/pulsar show:routes
```

## Next steps

You have a working Pulsar application with routes, controllers, and templates. Here is where to go next:

- **Extensions.** Read the [extension system guide](extensions.md) to learn how to build modular features. Pulsar's first-party extensions (admin panel, CMS, analytics, ORM) are built using the same extension API available to you.
- **Database.** Read the [database guide](database.md) to set up connections and run queries. The ORM extension (`pulsar/orm`) adds entity mapping, relationships, and a query builder on top of the core database layer.
- **Authentication.** Read the [authentication guide](authentication.md) to protect routes with guards, sessions, and two-factor authentication.
- **Middleware.** Read the [middleware guide](middleware.md) to add cross-cutting concerns like CORS, rate limiting, and logging.
- **Templates.** Read the [template engine guide](templating.md) for the full directive reference, context-aware escaping, and the untrusted template sandbox.
- **Configuration.** Read the [configuration guide](configuration.md) for typed DTOs, environment variable overrides, and per-environment settings.
- **CLI.** Run `php bin/pulsar list` to see all available commands, or read the [CLI reference](cli-reference.md).
