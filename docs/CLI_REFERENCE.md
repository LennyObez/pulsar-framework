# CLI Reference

Pulsar provides a command-line interface through the `bin/pulsar` entry point. The CLI is built on a custom console application with structured input/output, table formatting, and grouped command namespaces.

## Usage

```bash
php bin/pulsar <command> [options] [arguments]
```

## Global Options

These options are available on every command:

| Option      | Short | Description                              |
| ----------- | ----- | ---------------------------------------- |
| `--help`    | `-h`  | Display help for a command               |
| `--version` | `-V`  | Display the framework version            |
| `--quiet`   | `-q`  | Suppress all output                      |
| `--verbose` | `-v`  | Increase verbosity (`-v`, `-vv`, `-vvv`) |

Verbosity levels:

- Default -- Normal output.
- `-v` / `--verbose` -- Verbose output with additional context.
- `-vv` -- Very verbose output.
- `-vvv` -- Debug-level output including stack traces on errors.

## Built-in Commands

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

The `text` format groups commands by namespace (e.g., `show`, `scaffold`, `migrate`, `scheduler`, `health`). The `json` format outputs a JSON array of command objects with `name`, `namespace`, and `description` fields.

#### `init`

Initialize a new Pulsar project with a standard directory structure.

```bash
php bin/pulsar init my-project
php bin/pulsar init . --force
```

| Argument    | Required | Description                                      |
| ----------- | -------- | ------------------------------------------------ |
| `directory` | No       | Target directory (defaults to current directory) |

| Option    | Short | Description              |
| --------- | ----- | ------------------------ |
| `--force` | `-f`  | Overwrite existing files |

Creates the following structure: `app/` (Controllers, Middleware, Services), `config/`, `public/` (with `index.php`), `extensions/`, `tests/` (Unit, Integration), and `.gitignore`.

#### `diagnostics`

Display system diagnostics and health checks.

```bash
php bin/pulsar diagnostics
```

Outputs five sections:

- **Framework** -- Version, kernel boot status, extension count, route count.
- **PHP Environment** -- PHP version, SAPI, OS family, architecture.
- **PHP Extensions** -- Required extensions (json, mbstring, pcre) and optional extensions (opcache, apcu, redis) with status indicators.
- **Memory** -- Memory limit, current usage, peak usage.
- **Paths** -- Working directory, temp directory, Composer availability.

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

Output columns: Method, Path, Name, Handler, Middleware.

The handler column displays the class and method (e.g., `UserController::index`) or `Closure` for anonymous handlers. Middleware names are shortened to their class basename.

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

#### `scaffold:module`

Generate a new HMVC module structure.

```bash
php bin/pulsar scaffold:module User
php bin/pulsar scaffold:module blog-posts --path=app/Modules
```

| Argument | Required | Description                        |
| -------- | -------- | ---------------------------------- |
| `name`   | Yes      | Module name (e.g., `User`, `Blog`) |

| Option   | Short | Default       | Description                   |
| -------- | ----- | ------------- | ----------------------------- |
| `--path` | `-p`  | `app/Modules` | Base path for module creation |

Creates the following structure for a module named `User`:

```
app/Modules/User/
  Controllers/
    UserController.php
  Services/
    UserService.php
  Models/
  Middleware/
  Views/
  ModuleServiceProvider.php
  routes.php
```

The module name is automatically converted to PascalCase. Generated files include a service provider implementing `ServiceProviderInterface`, a controller with `index` and `show` methods, a service class, and a routes file.

#### `scaffold:extension`

Generate a new extension structure with manifest.

```bash
php bin/pulsar scaffold:extension my-feature
php bin/pulsar scaffold:extension payments --vendor=acme --path=extensions
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

### Database Migrations

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

### Health and Resilience

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

### Project Creation

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

### Queue

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

### File Integrity

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

### Deploy Readiness

#### `deploy:check`

Run deploy readiness checks for a target environment.

```bash
php bin/pulsar deploy:check
php bin/pulsar deploy:check --env=staging --json
```

| Option     | Short | Default      | Description                        |
| ---------- | ----- | ------------ | ---------------------------------- |
| `--env`    | `-e`  | `production` | Target environment to check        |
| `--json`   | `-j`  | --           | Output as JSON                     |
| `--strict` | `-s`  | --           | Fail on warnings (not just errors) |

Runs all registered deploy checks and produces a report with pass/warning/error counts. See [`docs/DEPLOYMENT.md`](DEPLOYMENT.md) for details.

### Scheduler

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

## Exit Codes

All commands use the `ExitCode` enum:

| Code | Name      | Meaning                             |
| ---- | --------- | ----------------------------------- |
| 0    | `Success` | Command completed successfully      |
| 1    | `Error`   | Command encountered a runtime error |
| 2    | `Invalid` | Invalid arguments or options        |

## Writing Custom Commands

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
