# CLI reference

Pulsar provides a command-line interface through the `bin/pulsar` entry point. The CLI is built on a custom console application with structured input/output, table formatting, and grouped command namespaces.

## Usage

```bash
php bin/pulsar <command> [options] [arguments]
```

## Global options

These options are available on every command:

| Option      | Short | Description                              |
| ----------- | ----- | ---------------------------------------- |
| `--help`    | `-h`  | Display help for a command               |
| `--version` | `-V`  | Display the framework version            |
| `--quiet`   | `-q`  | Suppress all output                      |
| `--verbose` | `-v`  | Increase verbosity (`-v`, `-vv`, `-vvv`) |

Verbosity levels:

- Default - Normal output.
- `-v` / `--verbose`: Verbose output with additional context.
- `-vv`: Very verbose output.
- `-vvv`: Debug-level output including stack traces on errors.

## Why `pulsar list` is shorter than this page

Most commands are always there. A subsystem's commands are not: they are
registered only when the subsystem's services are bound, and every subsystem that
carries operational risk ships **off**. Queue workers, the scheduler, integrity
manifests, the supervisor and the self-healing runner all begin with
`'enabled' => false` in their config file, so a clean checkout registers none of
their commands and `pulsar list` does not show them. That is the intended
posture, not a missing install: a framework that silently ran a queue worker or
rewrote an integrity manifest because a config file happened to exist would be
the worse default.

Each affected section below opens with the switch that turns it on. Turn the
subsystem on, rerun `php bin/pulsar list`, and the commands appear. If one is
still absent afterwards, `php bin/pulsar debug:wiring` names the binding that is
missing and why.

Commands contributed by an extension follow the same rule and additionally
require that extension to be enabled — bundled products are off until listed in
`extensions.enabled_products`. `php bin/pulsar extension:list` shows which are
loaded.

## Scope of this reference

The CLI ships **152 commands**. This page carries a full option-and-behaviour
section for 52 of them, and the table below names every one of the other 100
with its description and where it comes from. Nothing is silently omitted: a
command with no detailed section is still listed, and the "Detailed below"
column says which is which.

`php bin/pulsar help <command>` prints the arguments and options of any command
from its own declaration, and is authoritative when this page and the code
disagree. The index is checked against the code by
`tests/Unit/Integrity/DocumentedCliCommandsTest`, which fails the build when a
command exists that this table does not name - so the count above cannot drift
without someone noticing.

The `Ships with` column says which package declares the command. `core` is the
framework itself; `ext: <name>` is the bundled extension in
`extensions/<name>/`, which must be enabled before its commands appear (see
[Why `pulsar list` is shorter than this page](#why-pulsar-list-is-shorter-than-this-page)).

### Complete command index

| Command                                       | Description                                                                                       | Ships with         | Detailed below                |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------- | ------------------ | ----------------------------- |
| `a11y:audit`                                  | Run accessibility audit on template files (dev/CI only)                                           | ext: accessibility | -                             |
| `admin:serve`                                 | Start the Admin panel development server                                                          | ext: admin         | -                             |
| `api:routes`                                  | List registered API endpoints                                                                     | core               | -                             |
| `api:spec`                                    | Generate an OpenAPI v3.1 specification from registered routes                                     | core               | -                             |
| `asset:publish`                               | Publish resource directories to public/assets via symlinks                                        | core               | -                             |
| `backup:restore`                              | Restore a sealed archive, or rehearse the restore without writing                                 | core               | -                             |
| `backup:run`                                  | Take one sealed, tamper-evident backup archive and print its manifest                             | core               | -                             |
| `backup:verify`                               | Read a sealed archive back and re-digest every entry in it                                        | core               | -                             |
| `build`                                       | Compile all production artifacts deterministically                                                | core               | -                             |
| `cache:warmup`                                | Warm config, route, and container caches (alias for optimize)                                     | core               | [yes](#cachewarmup)           |
| `cms:import`                                  | Import CMS content from a JSON file or directory                                                  | ext: cms           | -                             |
| `cms:publish-scheduled`                       | Publish scheduled content and archive expired content                                             | ext: cms           | -                             |
| `cms:serve`                                   | Start the CMS development server                                                                  | ext: cms           | -                             |
| `cms:theme:activate`                          | Activate an installed CMS theme                                                                   | ext: cms           | -                             |
| `cms:theme:install`                           | Install a CMS theme from a local directory                                                        | ext: cms           | -                             |
| `compliance:report`                           | Assess the deployment against its enabled compliance frameworks                                   | core               | [yes](#compliancereport)      |
| `config:reference`                            | Generate a Markdown reference of all configuration options                                        | core               | -                             |
| `data:purge`                                  | Apply the retention policies in config/data_protection.php, deleting expired records              | core               | [yes](#datapurge)             |
| `db:failover:watch`                           | Watch the primary database and fail over to a standby when it becomes unreachable                 | core               | -                             |
| `db:fresh`                                    | Drop all tables, re-run migrations, and optionally seed                                           | core               | -                             |
| `db:inspect`                                  | Show database table structure, columns, and metadata                                              | core               | -                             |
| `db:seed`                                     | Run database seeders                                                                              | core               | -                             |
| `debug:config`                                | Show resolved configuration values with types                                                     | core               | -                             |
| `debug:container`                             | Debug container bindings, lifetimes, and scope validation                                         | core               | -                             |
| `debug:routes`                                | Show routes with full middleware stack and handler details                                        | core               | -                             |
| `debug:wiring`                                | Show service-wiring contracts and any degraded or unsatisfied bindings                            | core               | -                             |
| `deploy:check`                                | Run deploy readiness checks for a target environment                                              | core               | [yes](#deploycheck)           |
| `dev:start`                                   | Generate Docker development environment files                                                     | core               | -                             |
| `dev:status`                                  | Show the command to check Docker development environment status                                   | core               | -                             |
| `dev:stop`                                    | Show the command to stop the Docker development environment                                       | core               | -                             |
| `diagnostics`                                 | Display system diagnostics and health checks                                                      | core               | [yes](#diagnostics)           |
| `docs`                                        | List or open documentation topics                                                                 | core               | -                             |
| `doctor`                                      | Check your environment for Pulsar compatibility                                                   | core               | -                             |
| `export:run`                                  | Export data from registered providers                                                             | core               | -                             |
| `extension:list`                              | List installed extensions with status and version                                                 | core               | -                             |
| `extension:validate`                          | Validate an extension manifest and directory structure                                            | core               | -                             |
| `forum:serve`                                 | Start the Forum standalone development server                                                     | ext: forum         | -                             |
| `grpc:generate`                               | Generate PHP code from proto files using protoc                                                   | ext: grpc          | -                             |
| `grpc:serve`                                  | Start the gRPC server                                                                             | ext: grpc          | -                             |
| `health:check`                                | Run all registered health checks                                                                  | core               | [yes](#healthcheck)           |
| `health:repair`                               | Run self-healing repair jobs                                                                      | core               | [yes](#healthrepair)          |
| `help`                                        | Display the help screen                                                                           | core               | -                             |
| `i18n:extract`                                | Extract translation keys from PHP source files                                                    | core               | -                             |
| `i18n:lint`                                   | Validate translation catalogs                                                                     | core               | -                             |
| `i18n:slugs:lint`                             | Validate localized route slugs (completeness + collisions)                                        | core               | -                             |
| `ide:helper`                                  | Generate IDE helper file for autocompletion                                                       | core               | -                             |
| `import:run`                                  | Import data from a file via registered providers                                                  | core               | -                             |
| `init`                                        | Initialize a new Pulsar project                                                                   | core               | [yes](#init)                  |
| `integrity:build`                             | Build an integrity manifest from configured file paths                                            | core               | [yes](#integritybuild)        |
| `integrity:repair`                            | Regenerate integrity manifest from current filesystem state                                       | core               | [yes](#integrityrepair)       |
| `integrity:verify`                            | Verify filesystem integrity against a stored manifest                                             | core               | [yes](#integrityverify)       |
| `key:generate`                                | Generate a PULSAR_MASTER_KEY for cache integrity                                                  | core               | [yes](#keygenerate)           |
| `key:rotate`                                  | Rotate the master key (new key + previous key for fallback)                                       | core               | [yes](#keyrotate)             |
| `list`                                        | List all available commands                                                                       | core               | [yes](#list)                  |
| `maintenance:disable`                         | Disable maintenance mode and resume normal operation                                              | core               | -                             |
| `maintenance:enable`                          | Enable maintenance mode with optional bypass secret                                               | core               | -                             |
| `make:adapter`                                | Generate an adapter implementing a port interface                                                 | core               | [yes](#makeadapter)           |
| `make:crud`                                   | Generate full CRUD stack for an entity                                                            | core               | -                             |
| `make:event`                                  | Generate an event class                                                                           | core               | -                             |
| `make:event-ingestion`                        | Generate an event ingestion pipeline with webhook verification                                    | core               | [yes](#makeevent-ingestion)   |
| `make:extension`                              | Generate a new extension structure                                                                | core               | [yes](#makeextension)         |
| `make:feature`                                | Generate a vertical feature slice in a module                                                     | core               | [yes](#makefeature)           |
| `make:from-schema`                            | Import database tables into entity definitions                                                    | core               | -                             |
| `make:listener`                               | Generate a listener class for an event                                                            | core               | -                             |
| `make:migration-diff`                         | Generate migration from entity mapping metadata changes                                           | core               | -                             |
| `make:module`                                 | Generate a new module with Contracts/Internal separation                                          | core               | [yes](#makemodule)            |
| `make:payment-flow`                           | Generate a payment flow with idempotency and observability                                        | core               | [yes](#makepayment-flow)      |
| `make:port`                                   | Generate a new port interface in a module                                                         | core               | [yes](#makeport)              |
| `make:test`                                   | Generate a test file with method stubs matching source class public methods                       | core               | -                             |
| `make:webhook-handler`                        | Generate a webhook handler with verification and deduplication                                    | core               | [yes](#makewebhook-handler)   |
| `mcp:serve`                                   | Start the MCP server (JSON-RPC 2.0 over stdio)                                                    | ext: mcp-server    | -                             |
| `metadata:export`                             | Export project metadata as JSON                                                                   | core               | -                             |
| `migrate:create`                              | Create a new migration file                                                                       | core               | [yes](#migratecreate)         |
| `migrate:rollback`                            | Rollback the last batch of migrations                                                             | core               | [yes](#migraterollback)       |
| `migrate:run`                                 | Run all pending database migrations                                                               | core               | [yes](#migraterun)            |
| `migrate:status`                              | Show the status of each migration                                                                 | core               | [yes](#migratestatus)         |
| `new`                                         | Create a new Pulsar project                                                                       | core               | [yes](#new)                   |
| `optimize`                                    | Cache configuration, routes, and container for production                                         | core               | [yes](#optimize)              |
| `optimize:clear`                              | Clear all framework cache files                                                                   | core               | [yes](#optimizeclear)         |
| `optimize:validate`                           | Validate that framework cache generation succeeds and cache is loadable (CI)                      | core               | [yes](#optimizevalidate)      |
| `playground:serve`                            | Start the Playground development server                                                           | core               | -                             |
| `preload:dump`                                | Generate a deterministic OPcache preload script                                                   | core               | [yes](#preloaddump)           |
| `privacy-pass:keys:refresh`                   | Fetch and cache the Privacy Pass issuer directory token keys                                      | core               | -                             |
| `pulse:diff`                                  | Show compiled output for a Pulse template                                                         | core               | -                             |
| `queue:failed`                                | List all failed jobs                                                                              | core               | [yes](#queuefailed)           |
| `queue:flush`                                 | Purge all jobs from a queue                                                                       | core               | [yes](#queueflush)            |
| `queue:retry`                                 | Retry a failed job or all failed jobs                                                             | core               | [yes](#queueretry)            |
| `queue:status`                                | Display queue system status                                                                       | core               | [yes](#queuestatus)           |
| `queue:work`                                  | Start processing jobs from a queue                                                                | core               | [yes](#queuework)             |
| `remove:adapter`                              | Remove a scaffolded adapter and its test                                                          | core               | [yes](#removeadapter)         |
| `remove:event-ingestion`                      | Remove a scaffolded event ingestion pipeline and its files                                        | core               | [yes](#removeevent-ingestion) |
| `remove:extension`                            | Remove a scaffolded extension                                                                     | core               | [yes](#removeextension)       |
| `remove:feature`                              | Remove a scaffolded feature slice from a module                                                   | core               | [yes](#removefeature)         |
| `remove:module`                               | Remove a scaffolded module and its tests                                                          | core               | [yes](#removemodule)          |
| `remove:payment-flow`                         | Remove a scaffolded payment flow and its files                                                    | core               | [yes](#removepayment-flow)    |
| `remove:port`                                 | Remove a scaffolded port interface from a module                                                  | core               | [yes](#removeport)            |
| `remove:webhook-handler`                      | Remove a scaffolded webhook handler and its files                                                 | core               | [yes](#removewebhook-handler) |
| `repl`                                        | Start an interactive REPL with full framework context                                             | core               | -                             |
| `routes:cache`                                | Compile routes to a cached file for production                                                    | core               | -                             |
| `runtime:reload`                              | Send reload signal to the running persistent runtime                                              | core               | -                             |
| `runtime:serve`                               | Start the HTTP runtime server                                                                     | core               | [yes](#runtimeserve)          |
| `runtime:status`                              | Show available runtimes and current configuration                                                 | core               | -                             |
| `schedule:run`                                | Run all due scheduled tasks                                                                       | core               | -                             |
| `scheduler:list`                              | List all registered scheduled jobs                                                                | core               | [yes](#schedulerlist)         |
| `scheduler:tick`                              | Run all due scheduled jobs                                                                        | core               | [yes](#schedulertick)         |
| `secret:get`                                  | Retrieve a decrypted secret from the vault                                                        | core               | -                             |
| `secret:list`                                 | List all secret keys in the vault                                                                 | core               | -                             |
| `secret:set`                                  | Store a secret in the encrypted vault                                                             | core               | -                             |
| `security:check`                              | Report the application security posture (OK / DEGRADED / FAIL)                                    | core               | -                             |
| `self-update`                                 | Check for and apply Pulsar framework updates                                                      | core               | -                             |
| `serve`                                       | Start the PHP built-in development server                                                         | core               | -                             |
| `shell`                                       | Start an interactive REPL with framework context                                                  | core               | [yes](#shell)                 |
| `show:container`                              | Display container bindings                                                                        | core               | [yes](#showcontainer)         |
| `show:routes`                                 | Display all registered routes                                                                     | core               | [yes](#showroutes)            |
| `status`                                      | Show framework status overview                                                                    | core               | -                             |
| `studio:console:bench`                        | Run performance benchmarks and emit Studio events                                                 | ext: studio        | -                             |
| `studio:console:evidence:export`              | Export Studio events as evidence archive                                                          | ext: studio        | -                             |
| `studio:console:evidence:purge`               | Purge all events from evidence store                                                              | ext: studio        | -                             |
| `studio:console:evidence:redaction:test`      | Test redaction policies against sample data                                                       | ext: studio        | -                             |
| `studio:console:evidence:retention:apply`     | Apply retention policy to evidence store                                                          | ext: studio        | -                             |
| `studio:console:evidence:status`              | Display evidence store status                                                                     | ext: studio        | -                             |
| `studio:console:evidence:verify`              | Verify a Studio evidence archive                                                                  | ext: studio        | -                             |
| `studio:console:exceptions`                   | Display exception data from Studio                                                                | ext: studio        | -                             |
| `studio:console:export`                       | Export Studio events as evidence archive                                                          | ext: studio        | -                             |
| `studio:console:guardian:check`               | Run all guardian checks                                                                           | ext: studio        | -                             |
| `studio:console:guardian:deploy:check`        | Run deploy readiness checks                                                                       | ext: studio        | -                             |
| `studio:console:guardian:integrity:build`     | Build an integrity manifest                                                                       | ext: studio        | -                             |
| `studio:console:guardian:integrity:verify`    | Verify integrity manifest against filesystem                                                      | ext: studio        | -                             |
| `studio:console:guardian:status`              | Display combined guardian status overview                                                         | ext: studio        | -                             |
| `studio:console:guardian:supervisor:run-once` | Run a single supervisor evaluation cycle                                                          | ext: studio        | -                             |
| `studio:console:guardian:supervisor:status`   | Display supervisor configuration status                                                           | ext: studio        | -                             |
| `studio:console:jobs`                         | Display job processing data from Studio                                                           | ext: studio        | -                             |
| `studio:console:metrics`                      | Display aggregated Studio metrics                                                                 | ext: studio        | -                             |
| `studio:console:query`                        | Query Studio events                                                                               | ext: studio        | -                             |
| `studio:console:routes`                       | Display route performance data from Studio                                                        | ext: studio        | -                             |
| `studio:console:status`                       | Display Console event store status                                                                | ext: studio        | -                             |
| `studio:console:tail`                         | Stream Studio events in real-time                                                                 | ext: studio        | -                             |
| `studio:console:timeline`                     | Display a timeline of recent Studio events                                                        | ext: studio        | -                             |
| `studio:console:verify`                       | Verify a Studio evidence archive                                                                  | ext: studio        | -                             |
| `studio:disable`                              | Disable Studio in configuration                                                                   | ext: studio        | -                             |
| `studio:doctor`                               | Run Studio diagnostic checks                                                                      | ext: studio        | -                             |
| `studio:enable`                               | Enable Studio in configuration                                                                    | ext: studio        | -                             |
| `studio:open`                                 | Open Studio in the default browser                                                                | ext: studio        | -                             |
| `studio:serve`                                | Start the Studio development server                                                               | ext: studio        | -                             |
| `studio:status`                               | Display Studio status and configuration                                                           | ext: studio        | -                             |
| `supervisor:check`                            | Run supervisor preflight checks                                                                   | core               | [yes](#supervisorcheck)       |
| `supervisor:status`                           | Show supervisor configuration and policy info                                                     | core               | [yes](#supervisorstatus)      |
| `supply-chain:audit-pipeline`                 | Audit CI/CD pipeline configurations for security compliance                                       | core               | -                             |
| `supply-chain:licenses`                       | Check dependency licenses against the allowlist                                                   | core               | -                             |
| `supply-chain:sign`                           | Sign release artifacts with Ed25519                                                               | core               | -                             |
| `supply-chain:verify`                         | Verify release artifact Ed25519 signatures                                                        | core               | -                             |
| `supply-chain:vex`                            | Generate a VEX document for known vulnerabilities                                                 | core               | -                             |
| `test:changed`                                | Run tests for files changed since last commit                                                     | core               | -                             |
| `view:compile`                                | Pre-compile all templates to the cache directory                                                  | core               | -                             |
| `workflow:check-timeouts`                     | Detect workflow instances whose state timeout expired and dispatch WorkflowTimedOutEvent for each | core               | -                             |

## Built-in commands

### General

#### `list`

List all available commands, grouped by namespace.

```bash
php bin/pulsar list
php bin/pulsar list --format=json
```

| Option     | Short | Default | Description                     |
| ---------- | ----- | ------- | ------------------------------- |
| `--format` | `-f`  | `text`  | Output format: `text` or `json` |

The `text` format groups commands by namespace - the segment before the first `:` in the command name (e.g. `make`, `migrate`, `queue`, `debug`, `health`). Commands with no colon (`list`, `serve`, `doctor`) are grouped under the empty namespace and printed ungrouped. The `json` format outputs a JSON array of command objects with `name`, `namespace`, and `description` fields.

#### `init`

Initialize a new Pulsar project in place. Equivalent to
[`new`](#new) with `--preset=minimal --env=local`, which is what it delegates to.

```bash
php bin/pulsar init my-project
php bin/pulsar init .
```

| Argument    | Required | Description                                      |
| ----------- | -------- | ------------------------------------------------ |
| `directory` | No       | Target directory (defaults to current directory) |

`init` declares **no options**. There is no `--force`, and there is no way to
overwrite: before it writes anything, the generator lists every file it is about
to create, and if any of them already exists it aborts with the colliding names
and touches nothing. Point it at a directory that does not have them, or remove
them first. That refusal is the design - a scaffolder that can overwrite
`config/security.php` is a scaffolder that can silently disarm a project.

Creates:

```
config/                  app.php, security.php, extensions.php, database.php,
                         observability.php, i18n.php, view.php, cache.php, mail.php
public/index.php
src/
var/cache/
var/logs/
.env                     generated with a fresh master key
.gitignore
composer.json
```

`config/security.php` ships in every preset, not only this one: it is a required
config, and a project without it cannot complete `ConfigManager::load()` - so it
cannot boot, and cannot run a single command.

#### `diagnostics`

Display system diagnostics and health checks.

```bash
php bin/pulsar diagnostics
```

Outputs five sections:

- **Framework**: Version, kernel boot status, extension count, route count.
- **PHP Environment**: PHP version, SAPI, OS family, architecture.
- **PHP Extensions**: Required extensions (json, mbstring, pcre) and optional extensions (opcache, apcu, redis) with status indicators.
- **Memory**: Memory limit, current usage, peak usage.
- **Paths**: Working directory, temp directory, Composer availability.

### Inspection

#### `show:routes`

Display all registered routes in table format.

```bash
php bin/pulsar show:routes
php bin/pulsar show:routes --method=GET
php bin/pulsar show:routes --path=/api
```

| Option     | Short | Description            |
| ---------- | ----- | ---------------------- |
| `--method` | `-m`  | Filter by HTTP method  |
| `--path`   | `-p`  | Filter by path pattern |

Output columns: Method, Path, Name, Access, Handler, Middleware.

The handler column displays the class and method (e.g., `UserController::index`) or `Closure` for anonymous handlers. Middleware names are shortened to their class basename.

`Access` is the route's declared `Pulsar\Routing\RouteAccess` — `public`, `operator`, `signed` or `authenticated` — and `-` for a route that declares nothing. A `-` is not "unrestricted": `AuthorizationMiddleware` default-denies an empty permission list, so an undeclared route is reachable by anyone where that middleware is absent from the pipeline and by nobody where it is present. See [Declaring who may reach a route](authorization.md#declaring-who-may-reach-a-route).

#### `show:container`

Display all container bindings and cached instances.

```bash
php bin/pulsar show:container
php bin/pulsar show:container --filter=Auth
```

| Option     | Short | Description          |
| ---------- | ----- | -------------------- |
| `--filter` | `-f`  | Filter by binding ID |

Output is split into two sections: a table of all bindings (with ID and type: Binding or Instance) and a list of cached singleton instances. Long class names (over 60 characters) are automatically shortened for readability.

### Scaffolding

Module scaffolding is [`make:module`](#makemodule). There is no `scaffold:module`
— this page documented one for several releases, and no release ever registered
it.

#### `make:extension`

Generate a new extension structure with manifest.

```bash
php bin/pulsar make:extension my-feature
php bin/pulsar make:extension payments --vendor=acme --path=extensions
```

| Argument | Required | Description                         |
| -------- | -------- | ----------------------------------- |
| `name`   | Yes      | Extension name (e.g., `my-feature`) |

| Option     | Short | Default      | Description                      |
| ---------- | ----- | ------------ | -------------------------------- |
| `--vendor` | --    | `acme`       | Vendor name for namespacing      |
| `--path`   | `-p`  | `extensions` | Base path for extension creation |

Creates:

```
extensions/my-feature/
  pulsar.json              # Extension manifest
  composer.json            # Composer package definition
  src/
    MyFeatureExtension.php         # ExtensionInterface implementation
    MyFeatureServiceProvider.php   # ServiceProviderInterface implementation
    MyFeatureService.php           # Example service
    Controller/
      MyFeatureController.php      # Example controller
```

The extension name is converted to kebab-case for the directory and PascalCase for class names. The generated `pulsar.json` includes the current framework version as `min_version`.

### Database migrations

#### `migrate:run`

Run all pending database migrations.

```bash
php bin/pulsar migrate:run
```

Applies migrations that have not yet been executed. Each applied migration version is listed in the output. Returns exit code 0 on success, 1 on failure.

#### `migrate:rollback`

Rollback the last batch of migrations.

```bash
php bin/pulsar migrate:rollback
php bin/pulsar migrate:rollback --all
```

| Option  | Short | Description                     |
| ------- | ----- | ------------------------------- |
| `--all` | --    | Rollback all migrations (reset) |

A failing `down()` stops the run and prints two lines — the version that failed, then `Caused by:` with the reason. The framework's own storage migrations use that reason to refuse dropping a table that still holds rows; see [When a rollback refuses](migrations.md#when-a-rollback-refuses). Returns exit code 0 on success, 1 on failure.

#### `migrate:create`

Create a new migration file.

```bash
php bin/pulsar migrate:create create_users_table
```

| Argument | Required | Description                                 |
| -------- | -------- | ------------------------------------------- |
| `name`   | Yes      | Migration name (e.g., `create_users_table`) |

Generates a timestamped migration file (e.g., `20260204120000_create_users_table.php`) in the configured migrations directory. The file contains a skeleton implementing `MigrationInterface` with `up()` and `down()` methods.

#### `migrate:status`

Show the status of each migration.

```bash
php bin/pulsar migrate:status
```

Displays a table with columns: Version, Name, Status (Applied/Pending), Batch number, and Applied At timestamp.

### Health and resilience

> Registered only when the resilience subsystem is on: set `'enabled' => true`
> in `config/resilience.php`. Until then `health:check` and `health:repair` are
> absent from `pulsar list`.

#### `health:check`

Run all registered health checks.

```bash
php bin/pulsar health:check
```

Executes each registered `HealthCheckInterface` implementation and reports results. Each check shows status (OK, WARN, or FAIL), the check name, a status message, and response time in milliseconds. The overall exit code is 0 if all checks pass, 1 if any check fails.

#### `health:repair`

Run self-healing repair jobs.

```bash
php bin/pulsar health:repair
```

First diagnoses all registered repair jobs, then runs repairs for any that need attention. Each repair reports status (FIXED or FAILED) and lists the actions performed. Exit code 0 if all repairs succeed, 1 if any fail.

### Compliance

#### `compliance:report`

Assess the running deployment against the controls its enabled frameworks declare, and print the result.

```bash
php bin/pulsar compliance:report
php bin/pulsar compliance:report --framework=pci_dss
php bin/pulsar compliance:report --format=json > compliance.json
php bin/pulsar compliance:report --format=markdown > compliance.md
php bin/pulsar compliance:report --strict
```

Every outcome in the report is computed when the command runs, by a probe reading facts gathered out of the booted application. No control's status is written down anywhere, so none can be edited into passing: the only way to turn a gap green is to change the deployment, or to stop claiming the framework by removing it from `enabled_frameworks` in `config/compliance.php`.

**This command writes.** A control is only observed by being exercised, so gathering evidence opens a database
session, executes every registered health check, recomputes one HMAC per stored evidence record, and performs four
writes against the running deployment. Two are undone — a synthetic value tokenized through the live vault and then
removed, and an identifier pseudonymized and then erased through the Article 17 forget service. Two are not: one
Article 50 transparency declaration under a reserved surface id (bounded to a single entry however often you run the
report), and one `Low`-severity row in the incident register per run, carrying the source
`compliance.incident_register_probe`. See [Compliance](compliance.md) for why the incident row is deliberate and why
it cannot be taken back. `composer compliance:check` runs the same gathering and has the same effects.

Each finding names the probe that concluded it and every observation it rests on, with that observation's grade — `measured` (something ran), `resolved` (this concrete class answered), `declared` (a config value was read) or `asserted` (the operator's word) — and the class that produced it. Only `measured` and `resolved` evidence can carry a control to satisfied, so a control can never be satisfied by configuration alone.

Options:

| Option                          | Effect                                                                                                                                                                    |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--framework=<id>`              | Report on one enabled framework. A framework that is not enabled is refused, not assessed.                                                                                |
| `--format=text\|json\|markdown` | `text` (default) for a terminal, `markdown` for the document an assessor is handed, `json` for a pipeline. Under `--format=json` nothing but the document reaches stdout. |
| `--strict`                      | Also fail on partially satisfied controls.                                                                                                                                |

Exit codes:

| Code | Meaning                                                                                                                                                                                                                         |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 0    | Every control an enabled framework claims was observed.                                                                                                                                                                         |
| 1    | At least one claimed control was not observed, or an enabled framework has no mapping at all.                                                                                                                                   |
| 2    | The report could not be produced: unknown framework or format, evidence gathering failed, a probe returned a verdict its evidence cannot support, or no framework is enabled. "Nothing to assess" is deliberately never a pass. |

Controls Pulsar cannot observe — an approved policy, a signed breach register, a CI run for the deployed commit — are printed last as an operator checklist with the artefact each assessor should be shown. They are excluded from the coverage arithmetic and never affect the exit code.

The report is a statement about the boot that produced it. Its header records the SAPI for that reason: run it in the deployed image, as the deploying user, or the artefact describes a process that is not the one serving traffic.

`composer compliance:check` runs the same report and fails on any control claimed and not observed.

### Project creation

#### `new`

Create a new Pulsar project with a standard structure, secure `.env` generation, and configurable presets.

```bash
php bin/pulsar new my-app
php bin/pulsar new my-app --preset=api --env=production
```

| Argument | Required | Description                   |
| -------- | -------- | ----------------------------- |
| `name`   | Yes      | Project name / directory name |

| Option     | Short | Default | Description                        |
| ---------- | ----- | ------- | ---------------------------------- |
| `--preset` | `-p`  | `web`   | Project preset (web, api, minimal) |
| `--env`    | `-e`  | `local` | Target environment mode            |

Creates a project directory with application scaffolding, configuration files, a secure `.env` with generated keys, and a `composer.json` pre-configured for the selected preset.

### Interactive REPL

#### `shell`

Start an interactive PHP REPL with framework context. Requires [PsySH](https://psysh.org/) (`composer require --dev psy/psysh`). See [`docs/repl.md`](repl.md) for full details.

```bash
php bin/pulsar shell
php bin/pulsar shell --no-safe-mode
php bin/pulsar shell --i-know-what-im-doing --no-audit
```

| Option                   | Short | Description                                               |
| ------------------------ | ----- | --------------------------------------------------------- |
| `--no-safe-mode`         | --    | Disable safe mode (allow database writes, queue dispatch) |
| `--i-know-what-im-doing` | --    | Required for production REPL access                       |
| `--no-audit`             | --    | Disable audit logging for this session                    |

Starts a PsySH shell with `$container` (the DI container) and `$redactor` (the `SecretRedactor`, when available) in scope. Safe mode is enabled by default, wrapping database connections, queue drivers, storage adapters, and cache backends with read-only decorators.

The REPL is **disabled by default** in all environments. Enable it with `REPL_ENABLED=true` in your `.env` file. CI environments are always blocked. Production requires both `REPL_ENABLED=true` and the `--i-know-what-im-doing` flag.

### Optimization

#### `optimize`

Cache configuration, routes, and container bindings for production.

```bash
php bin/pulsar optimize
php bin/pulsar optimize --strict --encrypt
```

| Option      | Short | Description                                      |
| ----------- | ----- | ------------------------------------------------ |
| `--strict`  | `-s`  | Fail on any cache build warning                  |
| `--encrypt` | `-e`  | Encrypt cached data (requires PULSAR_MASTER_KEY) |

Builds and writes framework caches with an HMAC-signed manifest for integrity. The cache invalidation key is computed from config file contents and `composer.lock`.

#### `optimize:clear`

Clear all framework cache files.

```bash
php bin/pulsar optimize:clear
```

Removes all cached configuration, route, and container files.

#### `optimize:validate`

Validate that framework cache generation succeeds and the cache is loadable. Designed for CI pipelines.

```bash
php bin/pulsar optimize:validate
php bin/pulsar optimize:validate --strict --encrypt
```

| Option      | Short | Description                                      |
| ----------- | ----- | ------------------------------------------------ |
| `--strict`  | `-s`  | Fail if any closure-based route is detected      |
| `--encrypt` | `-e`  | Encrypt cached data (requires PULSAR_MASTER_KEY) |

Runs a full cache round-trip: warm, verify `isWarm()`, verify `load()` returns all sections, then clear. Exit code 0 on success, 1 on any failure. See [`docs/caching.md`](caching.md) for CI usage examples.

#### `cache:warmup`

Warm config, route, and container caches. This is an alias for `optimize` - same behavior, discoverable under the `cache:` namespace.

```bash
php bin/pulsar cache:warmup
php bin/pulsar cache:warmup --strict --encrypt
```

| Option      | Short | Description                                      |
| ----------- | ----- | ------------------------------------------------ |
| `--strict`  | `-s`  | Fail if any closure-based route is detected      |
| `--encrypt` | `-e`  | Encrypt cached data (requires PULSAR_MASTER_KEY) |

### Data protection

#### `data:purge`

Apply the retention policies declared in `config/data_protection.php`, deleting the records that have outlived them.

```bash
php bin/pulsar data:purge
php bin/pulsar data:purge --dry-run
```

| Option      | Short | Description                                                                |
| ----------- | ----- | -------------------------------------------------------------------------- |
| `--dry-run` | --    | Count expired records without deleting, whatever `purge.dry_run` is set to |

Retention is applied by exactly two things: this command and the `data-protection:purge` scheduled job (`purge.schedule`, default `0 3 * * *`). A category is only examined when it has BOTH a policy in `config/data_protection.php` and a purge implementation in the container — `audit_logs` and `user_sessions` by default. The command says so rather than reporting a silent success when neither is true.

Without the `--dry-run` flag the command obeys `purge.dry_run`, so a console run and a scheduled run are the same operation rather than two policies.

### Queue

> Registered only when the queue is on: set `'enabled' => true` in
> `config/queue.php`. `queue:work` additionally needs a bound worker factory
> rather than a bare driver — a worker built from the driver alone has no
> dead-letter queue, no retry policy, and no pipeline to decrypt an encrypted
> payload before a handler sees it — so with a driver configured but no factory,
> `queue:flush` appears and `queue:work` does not.

#### `queue:work`

Start processing jobs from a queue.

```bash
php bin/pulsar queue:work
php bin/pulsar queue:work emails --max-jobs=500 --memory=256
```

| Argument | Required | Description                           |
| -------- | -------- | ------------------------------------- |
| `queue`  | No       | Queue name (defaults to config value) |

| Option       | Short | Default | Description                                      |
| ------------ | ----- | ------- | ------------------------------------------------ |
| `--max-jobs` | --    | `1000`  | Maximum jobs before worker recycles              |
| `--memory`   | --    | `128`   | Memory limit in MB before worker recycles        |
| `--timeout`  | --    | `3600`  | Maximum uptime in seconds before worker recycles |
| `--sleep`    | --    | `1000`  | Sleep duration in ms when no jobs available      |

Starts a long-running worker that polls the queue, executes jobs, and automatically recycles when resource limits are reached. Handles SIGINT/SIGTERM for graceful shutdown on Unix.

#### `queue:status`

Display queue system status.

```bash
php bin/pulsar queue:status
php bin/pulsar queue:status --json
```

| Option   | Short | Description    |
| -------- | ----- | -------------- |
| `--json` | --    | Output as JSON |

Shows the configured driver, default queue name, and pending job counts.

#### `queue:failed`

List all failed jobs.

```bash
php bin/pulsar queue:failed
php bin/pulsar queue:failed --json
```

| Option   | Short | Description    |
| -------- | ----- | -------------- |
| `--json` | --    | Output as JSON |

Displays failed jobs from the dead letter queue with job ID, class, queue, failure reason, and timestamp.

#### `queue:retry`

Retry a failed job or all failed jobs.

```bash
php bin/pulsar queue:retry job-abc-123
php bin/pulsar queue:retry all
```

| Argument | Required | Description              |
| -------- | -------- | ------------------------ |
| `id`     | Yes      | Job ID or `all` to retry |

Moves failed jobs from the dead letter queue back to their original queue for reprocessing.

#### `queue:flush`

Purge all jobs from a queue.

```bash
php bin/pulsar queue:flush
php bin/pulsar queue:flush emails
```

| Argument | Required | Description                           |
| -------- | -------- | ------------------------------------- |
| `queue`  | No       | Queue name (defaults to config value) |

Removes all pending jobs from the specified queue.

### Supervisor

> Registered only when the supervisor is on: set `'enabled' => true` in
> `config/supervisor.php`. `supervisor:status` appears with the config alone;
> `supervisor:check` additionally needs the preflight runner bound.

#### `supervisor:check`

Run supervisor preflight checks.

```bash
php bin/pulsar supervisor:check
```

Executes all registered preflight checks (database connectivity, disk space, etc.) and reports results. Exit code 0 if all checks pass.

#### `supervisor:status`

Show supervisor configuration and policy info.

```bash
php bin/pulsar supervisor:status
php bin/pulsar supervisor:status --json
```

| Option   | Short | Description    |
| -------- | ----- | -------------- |
| `--json` | --    | Output as JSON |

Displays supervisor configuration including recycle thresholds, stuck job timeout, and registered check counts.

### File integrity

> Registered only when integrity checking is on: set `'enabled' => true` in
> `config/integrity.php`. Signing is separate again — `integrity:build` writes an
> unsigned manifest unless a signer is bound.

#### `integrity:build`

Build an integrity manifest from configured file paths.

```bash
php bin/pulsar integrity:build
php bin/pulsar integrity:build --sign --output=manifest.json
```

| Option     | Short | Description                                    |
| ---------- | ----- | ---------------------------------------------- |
| `--sign`   | `-s`  | Sign the manifest (requires PULSAR_MASTER_KEY) |
| `--output` | `-o`  | Output file path (defaults to config value)    |

Scans configured include paths, computes SHA-256 hashes for each file, and writes an integrity manifest.

#### `integrity:verify`

Verify filesystem integrity against a stored manifest.

```bash
php bin/pulsar integrity:verify
php bin/pulsar integrity:verify --strict --json
```

| Option     | Short | Description                       |
| ---------- | ----- | --------------------------------- |
| `--strict` | `-s`  | Fail on any added or missing file |
| `--json`   | `-j`  | Output as JSON                    |

Compares the stored manifest against the current filesystem and reports modified, missing, and added files.

#### `integrity:repair`

Regenerate the integrity manifest from the current filesystem state.

```bash
php bin/pulsar integrity:repair --confirm
```

| Option      | Short | Description                     |
| ----------- | ----- | ------------------------------- |
| `--confirm` | `-c`  | Required safety gate to proceed |

Rebuilds the manifest from the current filesystem. Requires `--confirm` to prevent accidental execution.

### Deploy readiness

#### `deploy:check`

Run deploy readiness checks for a target environment.

```bash
php bin/pulsar deploy:check
php bin/pulsar deploy:check --env=staging --json
```

| Option     | Short | Default      | Description                                                  |
| ---------- | ----- | ------------ | ------------------------------------------------------------ |
| `--env`    | `-e`  | `production` | Target environment to check                                  |
| `--json`   | `-j`  | --           | Output as JSON                                               |
| `--strict` | `-s`  | --           | Also refuse the deploy on warnings (errors always refuse it) |

Runs all registered deploy checks and produces a report with pass/warning/error counts. Exits `1` when any check is error-severity, so the command can be used directly as a deploy gate. See [`docs/deployment.md`](deployment.md) for details.

### Scheduler

> Registered only when the scheduler is on: set `'enabled' => true` in
> `config/scheduler.php`. `scheduler:list` needs the job registry bound and
> `scheduler:tick` needs the scheduler itself, so a half-wired scheduler shows
> one and not the other.

#### `scheduler:list`

List all registered scheduled jobs.

```bash
php bin/pulsar scheduler:list
```

Displays each job's name, cron expression, and description. Shows the total count of registered jobs.

#### `scheduler:tick`

Run all due scheduled jobs.

```bash
php bin/pulsar scheduler:tick
```

Evaluates all registered jobs and executes those that are due. Each job result shows its status and execution time. Typically invoked by a system cron entry running every minute:

```cron
* * * * * cd /path/to/project && php bin/pulsar scheduler:tick >> /dev/null 2>&1
```

### Runtime

#### `runtime:serve`

Start the persistent HTTP runtime server.

```bash
php bin/pulsar runtime:serve
php bin/pulsar runtime:serve --port 3000
php bin/pulsar runtime:serve --host 0.0.0.0 --port 8080 --public
```

| Option           | Short | Default     | Description                                |
| ---------------- | ----- | ----------- | ------------------------------------------ |
| `--host`         | --    | `127.0.0.1` | Address to bind                            |
| `--port`         | --    | `8080`      | Port to listen on                          |
| `--max-requests` | --    | `10000`     | Maximum requests before worker recycles    |
| `--memory`       | --    | `256`       | Memory threshold in MB before recycle      |
| `--timeout`      | --    | `7200`      | Time limit in seconds before recycle       |
| `--concurrency`  | --    | `0`         | `0` sync or `1`; above 1 is refused        |
| `--public`       | --    | --          | Required to bind to non-loopback addresses |

Requires `ext-sockets`. See [`docs/runtime.md`](runtime.md) for full details on configuration, safety rules, and deployment.

### Security keys

#### `key:generate`

Generate a cryptographically secure `PULSAR_MASTER_KEY`.

```bash
php bin/pulsar key:generate
php bin/pulsar key:generate --write
php bin/pulsar key:generate --write --force
```

| Option    | Short | Description                                                      |
| --------- | ----- | ---------------------------------------------------------------- |
| `--write` | `-w`  | Write the key to `.env` (creates from `.env.example` if missing) |
| `--force` | `-f`  | Overwrite an existing key without confirmation                   |

Without `--write`, prints the key to stdout. With `--write`, creates or updates the `.env` file.

#### `key:rotate`

Rotate the application master key.

```bash
php bin/pulsar key:rotate
php bin/pulsar key:rotate --write
php bin/pulsar key:rotate --write --clear-cache
```

| Option          | Short | Description                                |
| --------------- | ----- | ------------------------------------------ |
| `--write`       | `-w`  | Write the rotated keys to `.env`           |
| `--clear-cache` | `-c`  | Remind to clear cached data after rotation |

Generates a new master key and moves the current key to `PULSAR_MASTER_KEY_PREVIOUS` for a rotation window. Without `--write`, prints manual rotation instructions.

### Preloading

#### `preload:dump`

Generate a deterministic OPcache preload script.

```bash
php bin/pulsar preload:dump --output=preload.generated.php
php bin/pulsar preload:dump --output=preload.generated.php --strict
php bin/pulsar preload:dump --output=preload.generated.php --no-meta
```

| Option      | Short | Default                 | Description                                   |
| ----------- | ----- | ----------------------- | --------------------------------------------- |
| `--output`  | `-o`  | `preload.generated.php` | Output file path                              |
| `--strict`  | `-s`  | (default)               | Fail if any classmap entry cannot be resolved |
| `--lenient` | `-l`  | --                      | Skip invalid entries with warnings            |
| `--no-meta` | --    | --                      | Suppress `.meta.json` sidecar generation      |

The generated file is an immutable build artifact for use in `php.ini` with `opcache.preload`.

### DX scaffolding

These commands generate boundary-compliant code structures with `Contracts/Internal` separation, config DTOs, observability wiring, and test stubs. Each `make:*` command has a corresponding `remove:*` command.

#### `make:module`

Generate a new module with Contracts/Internal separation.

```bash
php bin/pulsar make:module <name> [--path=app/Modules] [--with-config] [--with-tests]
```

| Argument | Required | Description |
| -------- | -------- | ----------- |
| `name`   | Yes      | Module name |

| Option          | Short | Default       | Description                   |
| --------------- | ----- | ------------- | ----------------------------- |
| `--path`        | `-p`  | `app/Modules` | Base path for module creation |
| `--with-config` | --    | --            | Generate a config DTO         |
| `--with-tests`  | --    | --            | Generate test stubs           |

#### `make:feature`

Generate a vertical feature slice within a module.

```bash
php bin/pulsar make:feature <name> --module=<module> [--path=app/Modules] [--method=POST]
```

| Argument | Required | Description  |
| -------- | -------- | ------------ |
| `name`   | Yes      | Feature name |

| Option     | Short | Default       | Description               |
| ---------- | ----- | ------------- | ------------------------- |
| `--module` | --    | (required)    | Target module             |
| `--path`   | `-p`  | `app/Modules` | Base path                 |
| `--method` | --    | `POST`        | HTTP method for the route |

#### `make:port`

Generate a port interface in a module's Contracts directory.

```bash
php bin/pulsar make:port <name> --module=<module> [--path=app/Modules] [--methods=process,refund]
```

#### `make:adapter`

Generate an adapter implementing a port interface.

```bash
php bin/pulsar make:adapter <name> --port=<port> --module=<module> [--path=app/Modules]
```

#### `make:webhook-handler`

Generate a webhook handler with verification, deduplication, and HTTP controller.

```bash
php bin/pulsar make:webhook-handler <name> --module=<module> [--path=app/Modules]
```

#### `make:payment-flow`

Generate a complete payment flow with idempotency, audit logging, and metrics.

```bash
php bin/pulsar make:payment-flow <name> --module=<module> [--path=app/Modules]
```

#### `make:event-ingestion`

Generate an event ingestion pipeline with webhook verification and deduplication.

```bash
php bin/pulsar make:event-ingestion <name> --module=<module> [--path=app/Modules] [--events=push,pull_request]
```

#### `remove:module`

Remove a scaffolded module and its associated test directory.

```bash
php bin/pulsar remove:module <name> [--path=app/Modules] [--force] [--dry-run]
```

#### `remove:feature`

Remove a scaffolded feature slice from a module.

```bash
php bin/pulsar remove:feature <name> --module=<module> [--path=app/Modules] [--force] [--dry-run]
```

#### `remove:port`

Remove a scaffolded port interface from a module.

```bash
php bin/pulsar remove:port <name> --module=<module> [--path=app/Modules] [--force] [--dry-run]
```

#### `remove:adapter`

Remove a scaffolded adapter and its test file.

```bash
php bin/pulsar remove:adapter <name> --module=<module> [--path=app/Modules] [--force] [--dry-run]
```

#### `remove:webhook-handler`

Remove a scaffolded webhook handler and all its associated files.

```bash
php bin/pulsar remove:webhook-handler <name> --module=<module> [--path=app/Modules] [--force] [--dry-run]
```

#### `remove:payment-flow`

Remove a scaffolded payment flow and all its associated files.

```bash
php bin/pulsar remove:payment-flow <name> --module=<module> [--path=app/Modules] [--force] [--dry-run]
```

#### `remove:event-ingestion`

Remove a scaffolded event ingestion pipeline and all its associated files.

```bash
php bin/pulsar remove:event-ingestion <name> --module=<module> [--path=app/Modules] [--force] [--dry-run]
```

#### `remove:extension`

Remove a scaffolded extension directory.

```bash
php bin/pulsar remove:extension <name> [--path=extensions] [--force] [--dry-run]
```

All `remove:*` commands support `--force` (skip confirmation) and `--dry-run` (list files without deleting).

### Studio commands

Studio provides observability and debugging commands. See [`docs/studio.md`](studio.md) for full details.

#### Umbrella commands

| Command          | Description                           |
| ---------------- | ------------------------------------- |
| `studio:status`  | Show Studio status and storage stats  |
| `studio:serve`   | Start the Studio web server           |
| `studio:open`    | Open Studio in the default browser    |
| `studio:doctor`  | Run diagnostics (storage, port, keys) |
| `studio:enable`  | Enable Studio                         |
| `studio:disable` | Disable Studio                        |

#### Console commands

| Command                     | Description                           |
| --------------------------- | ------------------------------------- |
| `studio:console:status`     | Show event counts and retention stats |
| `studio:console:tail`       | Stream events in real-time            |
| `studio:console:query`      | Query events with filters             |
| `studio:console:export`     | Export evidence archive               |
| `studio:console:verify`     | Verify evidence chain integrity       |
| `studio:console:timeline`   | Display correlated event timeline     |
| `studio:console:metrics`    | Display collected metrics summary     |
| `studio:console:routes`     | Display route performance statistics  |
| `studio:console:exceptions` | Display exception groups and counts   |
| `studio:console:jobs`       | Display queue job statistics          |
| `studio:console:bench`      | Run benchmarks and display results    |

#### Evidence commands

| Command                                   | Description                                 |
| ----------------------------------------- | ------------------------------------------- |
| `studio:console:evidence:export`          | Export Studio events as evidence archive    |
| `studio:console:evidence:verify`          | Verify a Studio evidence archive            |
| `studio:console:evidence:status`          | Display evidence store status               |
| `studio:console:evidence:retention:apply` | Apply retention policy to evidence store    |
| `studio:console:evidence:purge`           | Purge all events from evidence store        |
| `studio:console:evidence:redaction:test`  | Test redaction policies against sample data |

#### Guardian commands

| Command                                       | Description                                     |
| --------------------------------------------- | ----------------------------------------------- |
| `studio:console:guardian:status`              | Display combined guardian status overview       |
| `studio:console:guardian:check`               | Run all guardian checks (preflight + invariant) |
| `studio:console:guardian:deploy:check`        | Run deploy readiness checks                     |
| `studio:console:guardian:supervisor:status`   | Display supervisor configuration status         |
| `studio:console:guardian:supervisor:run-once` | Run a single supervisor evaluation cycle        |
| `studio:console:guardian:integrity:build`     | Build an integrity manifest                     |
| `studio:console:guardian:integrity:verify`    | Verify integrity manifest against filesystem    |

All Studio, Evidence, and Guardian commands support `--json` for machine-readable output.

## Exit codes

All commands use the `ExitCode` enum:

| Code | Name      | Meaning                             |
| ---- | --------- | ----------------------------------- |
| 0    | `Success` | Command completed successfully      |
| 1    | `Error`   | Command encountered a runtime error |
| 2    | `Invalid` | Invalid arguments or options        |

## Writing custom commands

Implement `Pulsar\Console\CommandInterface` or extend `Pulsar\Console\Command`:

```php
<?php

declare(strict_types=1);

namespace App\Console;

use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

final class GreetCommand extends Command
{
    protected function configure(): void
    {
        $this->name = 'greet';
        $this->description = 'Greet a user';
        $this->addArgument('name', 'The name to greet', true);
        $this->addOption('shout', 'Uppercase the greeting', 's');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $message = "Hello, {$name}!";

        if ($input->hasOption('shout')) {
            $message = strtoupper($message);
        }

        $output->writeln($message);
        return ExitCode::Success->value;
    }
}
```

The `CommandInterface` uses PHP 8.4 property hooks for `$name` and `$description`. The abstract `Command` base class provides `addArgument()`, `addOption()`, and `getUsage()` helpers.

Register custom commands via extensions or directly on the application instance.
