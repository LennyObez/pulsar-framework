# Migrations

Pulsar migrations use timestamped PHP files containing anonymous classes. No naming collisions, zero boilerplate, version derived from filename.

## Creating Migrations

```bash
php bin/pulsar migrate:create create_users_table
```

This generates a file like `database/migrations/20260203153000_create_users_table.php`:

```php
<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute('
            CREATE TABLE users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS users');
    }
};
```

## Filename Convention

```
{YYYYMMDDHHMMSS}_description_snake_case.php
```

The 14-digit timestamp is the migration version. Migrations are applied in version order (ascending).

## Running Migrations

```bash
# Run all pending migrations
php bin/pulsar migrate:run

# Check migration status
php bin/pulsar migrate:status
```

## Rolling Back

```bash
# Rollback the last batch
php bin/pulsar migrate:rollback

# Rollback ALL migrations (reset)
php bin/pulsar migrate:rollback --all
```

## Batch System

Each `migrate:run` call assigns a batch number. All migrations applied in one run share the same batch. `migrate:rollback` rolls back only the most recent batch, in reverse version order.

Example:

| Version        | Name            | Batch |
| -------------- | --------------- | ----- |
| 20260101120000 | create_users    | 1     |
| 20260102120000 | create_posts    | 1     |
| 20260201120000 | add_user_avatar | 2     |

Running `migrate:rollback` rolls back only `20260201120000` (batch 2).

## Status

```bash
php bin/pulsar migrate:status
```

Output:

```
  Version           Name                                      Status      Batch    Applied At
  ----------------------------------------------------------------------------------------------------
  20260101120000    create_users                              Applied     1        2026-01-01 12:00:00
  20260102120000    create_posts                              Applied     1        2026-01-01 12:00:01
  20260201120000    add_user_avatar                           Pending     -        -
```

## Migration Tracking Table

Pulsar creates a tracking table (default: `pulsar_migrations`) automatically. Schema is driver-aware:

- **SQLite**: `INTEGER PRIMARY KEY AUTOINCREMENT`
- **MySQL**: `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` with InnoDB
- **PostgreSQL**: `SERIAL PRIMARY KEY`

Columns: `id`, `version` (unique), `name`, `batch`, `applied_at`.

## Configuration

In `config/database.php`:

```php
'migrations' => [
    'table' => 'pulsar_migrations',  // Tracking table name
    'path' => 'database/migrations', // Migration files directory
],
```

## Programmatic Usage

```php
use Pulsar\Database\Migration\MigrationRunner;
use Pulsar\Database\Migration\MigrationRepository;

$repository = new MigrationRepository('database/migrations');
$runner = new MigrationRunner($connection, $repository, 'pulsar_migrations');

// Run pending
$applied = $runner->runPending();

// Rollback last batch
$rolledBack = $runner->rollbackLastBatch();

// Reset everything
$runner->reset();

// Get status
$applied = $runner->getApplied();
$pending = $runner->getPending();
$batch = $runner->getCurrentBatch();
```
