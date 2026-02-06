# Installation Guide

Pulsar Framework 1.0.0-rc.1 -- Installation and setup for PHP 8.5 HMVC applications targeting regulated, mission-critical domains.

## System Requirements

| Requirement  | Minimum Version | Notes                                |
| ------------ | --------------- | ------------------------------------ |
| PHP          | 8.5.0           | CLI and web SAPI                     |
| Composer     | 2.6+            | Dependency management                |
| ext-ctype    | (bundled)       | Character type checking              |
| ext-mbstring | (bundled)       | Multibyte string support             |
| ext-pdo      | (bundled)       | Database abstraction                 |
| ext-sodium   | (bundled)       | Cryptographic operations             |
| ext-json     | (bundled)       | JSON encoding/decoding               |
| ext-pcre     | (bundled)       | Regular expression support           |
| ext-opcache  | (recommended)   | Required for JIT and preloading      |
| ext-apcu     | (optional)      | In-memory caching for config/routing |
| ext-redis    | (optional)      | Redis-backed sessions and caching    |

## Installation via Composer

```bash
composer require pulsar/framework
```

For a new project, use the `init` command after installation:

```bash
php bin/pulsar init my-project
cd my-project
composer install
```

The `init` command creates a project skeleton with the following structure:

```
my-project/
  app/
    Controllers/
    Middleware/
    Services/
  config/
  public/
    index.php
  extensions/
  tests/
    Unit/
    Integration/
  .gitignore
```

## Project Structure Overview

A Pulsar application follows this layout:

```
project-root/
  src/                  # Framework core (Pulsar\ namespace)
    Api/                # API stability attributes (#[Api], #[Internal])
    Auth/               # Authentication, authorization, 2FA
    Config/             # Typed configuration DTOs and loaders
    Console/            # CLI application and built-in commands
    Container/          # PSR-11 DI container
    Core/               # Kernel lifecycle and version
    Database/           # DB abstraction, migrations, connection management
    ErrorHandling/      # Exception rendering and HTTP exceptions
    Extensibility/      # Extension system (manifests, lifecycle, providers)
    FeatureFlag/        # Feature flag management
    Http/               # Request/Response, middleware, validation, rate limiting
    Observability/      # Logging, metrics, tracing, error tracking
    Resilience/         # Health checks, circuit breaker, retry, self-healing
    Routing/            # Router, routes, route groups, matching
    Scheduler/          # Job scheduling with cron expressions
    Security/           # Sessions, CSRF protection, audit logging
    Tenancy/            # Multi-tenancy support
  config/               # Configuration stubs
    app.php             # Application name, debug mode, extension paths
    database.php        # Database connections and drivers
    features.php        # Feature flag definitions
    observability.php   # Logging, metrics, tracing configuration
    resilience.php      # Health checks, circuit breaker, retry policies
    scheduler.php       # Scheduled job configuration
    security.php        # Session, CSRF, rate limiting, audit settings
    tenancy.php         # Multi-tenant configuration
  extensions/           # First-party and third-party extensions
  tests/                # Test suites (Unit, Integration, E2E)
  tools/                # Analyzer and runner configurations
  bin/pulsar            # CLI entry point
```

## Configuration Setup

Pulsar uses typed configuration DTOs loaded from PHP files in the `config/` directory. Each configuration file returns an associative array that maps to a readonly DTO.

Copy the configuration stubs to your project:

```bash
cp -r vendor/pulsar/framework/config/ config/
```

Edit the stubs to match your environment. At minimum, review:

- **config/app.php** -- Application name, debug mode, and extension discovery paths.
- **config/security.php** -- Session handling, CSRF tokens, rate limiting, and audit configuration.
- **config/database.php** -- Database driver, host, credentials, and connection pooling.

Configuration values are loaded once at boot time and are immutable at runtime. Override values per-environment using `ConfigOverrides` or environment-specific config files.

## First Run

Verify your installation by running the built-in diagnostics command:

```bash
php bin/pulsar diagnostics
```

This displays:

- Framework version and kernel boot status
- Number of loaded extensions and registered routes
- PHP version, SAPI, OS, and architecture
- Required and optional PHP extension status
- Memory usage and limits
- Working directory and Composer availability

A healthy output looks like:

```
Pulsar Framework Diagnostics
========================================

[INFO] Framework
  Version:      1.0.0-rc.1
  Kernel:       Booted
  Extensions:   0 loaded
  Routes:       1 registered

[INFO] PHP Environment
  Version:      8.5.0
  SAPI:         cli
  OS:           Linux
  Architecture: 64-bit

[INFO] PHP Extensions
  Required:
    [+] json            OK
    [+] mbstring        OK
    [+] pcre            OK
  Optional:
    [+] opcache         Loaded
    [ ] apcu            Not loaded
    [ ] redis           Not loaded
```

## Running the Development Server

Start the built-in PHP development server:

```bash
php -S localhost:8000 -t public
```

Then visit `http://localhost:8000` in your browser.

## Docker Quick-Start

For containerized deployments, your Dockerfile should:

1. Use an official PHP 8.5 image (e.g., `php:8.5-fpm-alpine` or `php:8.5-cli-alpine`).
2. Install the required extensions: `ctype`, `mbstring`, `pdo`, `sodium`, `opcache`.
3. Install Composer and run `composer install --no-dev --optimize-autoloader`.
4. Set the document root to `public/`.
5. Configure OPcache for production (`opcache.enable=1`, `opcache.validate_timestamps=0`).

Example minimal Dockerfile:

```dockerfile
FROM php:8.5-fpm-alpine

RUN docker-php-ext-install pdo pdo_mysql opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader

# Generate preload script (immutable build artifact)
RUN php bin/pulsar preload:dump --output=preload.generated.php --no-meta

# OPcache + JIT + preload configuration
RUN echo "opcache.enable=1\n\
opcache.jit=tracing\n\
opcache.jit_buffer_size=128M\n\
opcache.validate_timestamps=0\n\
opcache.preload=/app/preload.generated.php\n\
opcache.preload_user=www-data" > /usr/local/etc/php/conf.d/opcache.ini

EXPOSE 9000
CMD ["php-fpm"]
```

Pair with an Nginx or Caddy reverse proxy for production use.

## Next Steps

- Read the [CLI Reference](CLI_REFERENCE.md) to explore available commands.
- Read the [Extensions Guide](EXTENSIONS.md) to build modular features.
- Read the [Public API Reference](PUBLIC_API.md) to understand semver guarantees.
- Run `php bin/pulsar list` to see all registered commands.
