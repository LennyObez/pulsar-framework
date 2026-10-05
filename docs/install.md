# Installation guide

Pulsar Framework 1.0.0-rc.12: Installation and setup for PHP 8.5 HMVC applications targeting regulated, mission-critical domains.

## System requirements

| Requirement   | Minimum Version | Notes                                                                      |
| ------------- | --------------- | -------------------------------------------------------------------------- |
| PHP           | 8.5.0           | CLI and web SAPI                                                           |
| Composer      | 2.6+            | Dependency management                                                      |
| ext-ctype     | (bundled)       | Character type checking                                                    |
| ext-curl      | (bundled)       | Outbound HTTP (fetcher, OAuth2 client, webhook delivery)                   |
| ext-dom       | (bundled)       | XML parsing for SVG/PDF metadata validation (CMS/Accessibility extensions) |
| ext-exif      | (bundled)       | Image-metadata extraction (CMS media pipeline, photo upload validators)    |
| ext-fileinfo  | (bundled)       | MIME-type detection on uploaded files                                      |
| ext-gd        | (bundled)       | Image processing pipeline (CMS thumbnails, accessibility contrast checks)  |
| ext-json      | (bundled)       | JSON encoding/decoding                                                     |
| ext-libxml    | (bundled)       | Underlying XML parser used by ext-dom and ext-simplexml                    |
| ext-mbstring  | (bundled)       | Multibyte string support                                                   |
| ext-openssl   | (bundled)       | TLS for outbound HTTP, JWT signing fallbacks (libsodium remains primary)   |
| ext-pcre      | (bundled)       | Regular expression support                                                 |
| ext-pdo       | (bundled)       | Database abstraction                                                       |
| ext-simplexml | (bundled)       | OAS / RSS / sitemap fixture parsing                                        |
| ext-sodium    | (bundled)       | Cryptographic operations (HMAC chain, AEAD, KDF — see ADR-0006)            |
| ext-zip       | (bundled)       | Asset bundle export, language-pack archives, OAS bundle ingestion          |
| ext-opcache   | (recommended)   | Required for JIT and preloading                                            |
| ext-apcu      | (optional)      | In-memory caching for config/routing                                       |
| ext-iconv     | (optional)      | Optimised slug generation                                                  |
| ext-intl      | (optional)      | ICU MessageFormat, number/date/currency formatting                         |
| ext-redis     | (optional)      | Redis-backed sessions and caching                                          |

### Migration note: rc.10 → rc.11

The required extension set expanded in rc.11 with the addition of the
CMS, Accessibility, and OAuth2/WebAuthn extensions. If you are
upgrading from rc.10 or earlier, install the missing extensions before
running `composer install` against the new lockfile, otherwise the
`composer install` step will fail with `PHP extension XXX is required`.

Verify your environment with:

```bash
php -m | grep -E '^(curl|dom|exif|fileinfo|gd|libxml|openssl|simplexml|zip)$'
```

All listed extensions should appear. Missing extensions are typically
installed by adding the matching package on Debian/Ubuntu (`apt install
php8.5-curl php8.5-gd php8.5-zip php8.5-xml`), Alpine
(`apk add php85-curl php85-gd php85-zip php85-dom php85-exif`), or via
the relevant `docker-php-ext-install` line in your Dockerfile.

## Installation via Composer

```bash
composer require pulsar/framework
```

### While Pulsar is a release candidate

`pulsar/framework` is the package name, and it is the name to require — but it is
not on Packagist yet. Publication waits for the `1.0.0` tag, so the command above
resolves nothing today and reports `Could not find a matching version of package
pulsar/framework`. That is the intended state of an unreleased package, not a
mistake in the command.

Until the tag exists, tell Composer where to find it. From a checkout — the form
that gives you the release candidate by version, and the one the `composer.json`
that `pulsar init` writes is already set up for:

```bash
git clone https://github.com/LennyObez/pulsar-framework.git ../pulsar-framework
composer config repositories.pulsar path ../pulsar-framework
composer require pulsar/framework:^1.0.0-rc.12
```

A path repository reads the framework's own `composer.json`, so the release
candidate's version is there for a constraint to match, and Composer symlinks the
checkout instead of copying it. The constraint carries its own stability flag, so
the project needs no `minimum-stability` relaxation.

Or straight from the repository, with no checkout of your own:

```bash
composer config repositories.pulsar vcs https://github.com/LennyObez/pulsar-framework
composer require pulsar/framework:dev-main
```

Note the branch name rather than a version, and do not substitute one. Releases
here are cut from `composer.json` and the history carries no release tags yet, so
a VCS repository offers `dev-<branch>` and nothing else — a versioned constraint
has nothing to resolve against until `1.0.0` is tagged. `dev-main` is the most
recently released line.

Both forms are additive: once `1.0.0` is published, drop the `repositories` entry
with `composer config --unset repositories.pulsar` and the plain
`composer require pulsar/framework` above is all you need. Nothing else in this
guide changes.

Bundled extensions are a different case, and it is worth stating plainly because
older guides got it wrong: `pulsar/auth`, `pulsar/cms`, `pulsar/orm` and the rest
ship **inside** the framework under `extensions/`. An application that has
`pulsar/framework` already has all of them on disk. They are never installed with
`composer require` — not now, and not after the `1.0.0` tag. They are turned on in
`config/app.php`:

```php
'extensions' => [
    // Additive: switch specific off-by-default products on, leaving the rest of
    // the default posture intact.
    'enabled_products' => ['pulsar/cms'],
],
```

Infrastructure and security extensions — `pulsar/auth` among them — load without
any entry at all. Application products (`cms`, `forum`, `payments`, `tickets`,
`booking`, `analytics` and the others that declare `"kind": "product"`) are present
but off until listed, so a regulated application never inherits a forum it did not
ask for. `php bin/pulsar extension:list` shows which is which in your tree.

For a new project, use the `init` command after installation. The binary is
`vendor/bin/pulsar`: `composer.json` declares `"bin": ["bin/pulsar"]`, so Composer
installs the proxy under `vendor/bin/` and a consuming project has no `bin/` of
its own.

```bash
php vendor/bin/pulsar init my-project
cd my-project
composer install
```

Nothing has to be copied into the project first, and earlier revisions of this page
said otherwise. They told you that a directory with no `config/` of its own would
report `Kernel boot failed: Failed to boot extension "pulsar/mcp-server": ...
Community tier does not have ConfigWrite capability` — that every command exited 1,
`init` included — and to work around it with
`cp -r vendor/pulsar/framework/config/ config/`. That was a real defect, not a
missing step: `bin/pulsar` declined to fall back to the framework's own `config/`
outside a framework checkout, but loaded the framework's bundled `extensions/`
anyway, so the trust list was empty, every bundled extension was capped to
Community, and boot stopped at the first one needing more.

A directory with no `config/` is not an application, it is where one is about to be
created, so nothing is extended there any more and the commands run. The workaround
is gone with the defect — copying the framework's stubs into a project you are about
to scaffold would only leave `init` refusing to write over them.

**During the release candidate, add the repository inside the new project before
`composer install`.** `init` writes a `composer.json` requiring
`pulsar/framework` at `^1.0.0-rc.12`, and that package is not on Packagist until
the `1.0.0` tag, so the install step above cannot resolve it on its own:

```bash
php vendor/bin/pulsar init my-project
cd my-project
composer config repositories.pulsar path ../../pulsar-framework
composer install
```

That is the framework checkout cloned at the top of this section, now two levels
up: once for the project you ran `init` from, once for the new project inside it.
Any path to the checkout does — Composer resolves it relative to the file it
writes it into.

It has to be a **path** repository rather than a VCS one, and the distinction is
load-bearing. A VCS repository derives its versions from tags, this history
carries none yet, so `composer install` refuses the generated constraint with
`Root composer.json requires pulsar/framework ^1.0.0-rc.12, found
pulsar/framework[dev-main, ...] but it does not match the constraint`. A path
repository reads the checkout's own `composer.json`, where the release candidate's
version is declared, so the constraint resolves. Use the VCS form only with the
`dev-main` constraint given above.

Once the tag is published, the extra line goes away and the three-step form above
is the whole of it.

`init` uses the Minimal preset — the same output as `pulsar new --preset=minimal`
— and creates exactly this:

```
my-project/
  config/
    app.php            # Name, debug flag, extension enable posture
    security.php       # Sessions, CSRF, security headers
    extensions.php     # Extension trust tiers
    database.php
    observability.php
    i18n.php
    view.php
    cache.php
    mail.php
  public/
    index.php          # Entry point: registers one route and runs the kernel
  src/                 # Your App\ namespace, wired by the generated composer.json
  var/
    cache/
    logs/
  composer.json
  .gitignore
  <environment file>   # Generated with a fresh APP_KEY and PULSAR_MASTER_KEY
```

`pulsar new my-project --preset=web` adds `resources/views/` with a welcome
template the entry point renders; `--preset=api` adds `src/Http/Controller/` with
a health endpoint. Neither creates `app/`, `extensions/` or `tests/` — earlier
revisions of this page listed all three, and no preset has ever produced them.

`init` will not write over anything. Point it at a directory that already holds
one of the files above — a `composer.json` from `composer require`, most often —
and it names the collision and stops rather than replacing your file.

## Project structure overview

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

## Configuration setup

Pulsar uses typed configuration DTOs loaded from PHP files in the `config/` directory. Each configuration file returns an associative array that maps to a readonly DTO.

Copy the configuration stubs to your project:

```bash
cp -r vendor/pulsar/framework/config/ config/
```

Edit the stubs to match your environment. At minimum, review:

- **config/app.php**: Application name, debug mode, and extension discovery paths.
- **config/security.php**: Session handling, CSRF tokens, rate limiting, and audit configuration.
- **config/database.php**: Database driver, host, credentials, migrations, and read/write routing.

Configuration values are loaded once at boot time and are immutable at runtime. Override values per-environment using `ConfigOverrides` or environment-specific config files.

## First run

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
  Version:      1.0.0-rc.12
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

## Security setup

Generate a master key for cache integrity, encryption, and audit chain signing:

```bash
php bin/pulsar key:generate --write
```

This creates or updates the `.env` file with a cryptographically secure `PULSAR_MASTER_KEY`. If `.env` does not exist, the command copies `.env.example` as a template (if available). The master key enables:

- HMAC-signed framework caches
- Authenticated encryption (XSalsa20-Poly1305)
- Audit log chain integrity (BLAKE2b)
- Studio evidence chain MAC verification

If `PULSAR_MASTER_KEY` is not set, crypto-dependent services are not registered. Session management, CSRF protection, and security headers still function without a master key.

For key rotation procedures, see [Key Rotation](key-rotation.md).

## Running the development server

Start the Pulsar persistent runtime server:

```bash
php bin/pulsar runtime:serve
```

This starts a persistent worker on `127.0.0.1:8080` that boots the kernel once and handles many requests without per-request bootstrap overhead. Visit `http://localhost:8080` in your browser.

Custom port:

```bash
php bin/pulsar runtime:serve --port 3000
```

Alternatively, use the built-in PHP development server:

```bash
php -S localhost:8000 -t public
```

See [Runtime](runtime.md) for full persistent runtime documentation including configuration, safety rules, and production deployment.

## Platform notes

### Windows

Pulsar runs on Windows with PHP 8.5+. Some notes:

- Use `php bin/pulsar` from PowerShell or CMD. All CLI commands work cross-platform.
- Copy config stubs with `xcopy` instead of `cp`: `xcopy vendor\pulsar\framework\config config\ /E /I`
- The `runtime:serve` command uses `socket_select()` which behaves differently on Windows. Test on your target deployment platform.
- For `key:generate --write`, the `.env` file uses LF line endings regardless of platform.
- Scheduler cron entries (`scheduler:tick`) can be configured via Windows Task Scheduler instead of crontab.

### Linux / macOS

No special considerations. All commands work as documented.

## Docker quick-start

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

## Next steps

- Read the [CLI Reference](cli-reference.md) to explore available commands.
- Read the [Extensions Guide](extensions.md) to build modular features.
- Read the [Public API Reference](public-api.md) to understand semver guarantees.
- Run `php bin/pulsar list` to see all registered commands.
