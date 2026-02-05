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
