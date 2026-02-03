# Deployment Guide

This document covers deployment readiness checks, optimization, and operational best practices for Pulsar applications.

## Pre-Deployment Checklist

Before deploying to staging or production:

1. Run `php bin/pulsar optimize` to build framework caches
2. Run `php bin/pulsar deploy:check --env=production` to validate readiness
3. Run `php bin/pulsar integrity:build --sign` to create a signed integrity manifest
4. Verify all health checks pass: `php bin/pulsar health:check`

## Framework Optimization

The `optimize` command caches configuration, routes, and container bindings for production performance:

```bash
php bin/pulsar optimize
```

This creates cached files with an HMAC-signed manifest in the configured cache directory. The cache invalidation key is computed from a hash of config file contents and `composer.lock`, so caches are automatically invalidated when dependencies or configuration change.

To encrypt cached data (recommended for sensitive configuration):

```bash
php bin/pulsar optimize --encrypt
```

Encryption requires `PULSAR_MASTER_KEY` to be set. The cache uses XSalsa20-Poly1305 for encryption at rest.

To clear all caches:

```bash
php bin/pulsar optimize:clear
```

### Cache-Aware Boot

When framework caches are present, the Kernel boot path skips file-based config parsing and loads the pre-built `ConfigRepository` directly from cache. This eliminates config file I/O and PHP array merging on every request.

The boot pipeline detects cached data automatically — no code changes are needed.

## Deploy Checks

Deploy checks validate environment-specific requirements before deployment. Run them with:

```bash
php bin/pulsar deploy:check --env=production
php bin/pulsar deploy:check --env=staging --json
```

### Check Results

Each check returns one of three severity levels:

| Severity | Meaning                                       |
| -------- | --------------------------------------------- |
| Pass     | Check passed                                  |
| Warning  | Non-critical issue, deployment can proceed    |
| Error    | Critical issue, deployment should not proceed |

Use `--strict` to treat warnings as errors:

```bash
php bin/pulsar deploy:check --strict
```

### Writing Custom Checks

Implement `DeployCheckInterface` to add custom deploy checks:

```php
<?php

declare(strict_types=1);

namespace App\Deploy;

use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

final class DatabaseConnectionCheck implements DeployCheckInterface
{
    public function getName(): string
    {
        return 'database_connection';
    }

    public function getDescription(): string
    {
        return 'Verify database connectivity';
    }

    public function check(string $environment): CheckResult
    {
        // Run your check logic...
        return CheckResult::pass('Database connection established');
    }
}
```

Register checks via the container or an extension service provider.

## File Integrity

Pulsar includes a file integrity system for detecting unauthorized filesystem changes in production. See [`docs/INTEGRITY.md`](INTEGRITY.md) for details.

Build and sign a manifest before deployment:

```bash
php bin/pulsar integrity:build --sign
```

Verify integrity on the target server:

```bash
php bin/pulsar integrity:verify --strict
```

## Queue Workers

### Starting Workers

Start queue workers with resource limits:

```bash
php bin/pulsar queue:work --max-jobs=1000 --memory=256 --timeout=3600
```

Workers automatically recycle when any limit is reached, allowing a process manager (systemd, supervisord) to restart them cleanly.

### Process Management

Use a process manager to keep workers running. Example systemd unit:

```ini
[Unit]
Description=Pulsar Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=php bin/pulsar queue:work --max-jobs=1000 --memory=256
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Monitoring Queue Health

Check queue status and failed jobs:

```bash
php bin/pulsar queue:status --json
php bin/pulsar queue:failed --json
```

Retry failed jobs:

```bash
php bin/pulsar queue:retry all
php bin/pulsar queue:retry job-abc-123
```

## Supervisor

The supervisor subsystem monitors worker health and handles stuck job detection. See the supervisor commands:

```bash
php bin/pulsar supervisor:check     # Run preflight checks
php bin/pulsar supervisor:status    # Show configuration and policy info
```

### Preflight Checks

Preflight checks run before a worker starts processing to validate prerequisites (database connectivity, disk space, etc.). If any check fails, the worker should not start.

### Stuck Job Detection

The supervisor detects jobs that exceed their expected execution time and can automatically dead-letter them for manual investigation.

## Studio Guardian

Studio Guardian provides an integrated operational dashboard through the CLI. It combines supervisor status, deploy checks, and integrity verification into a single interface:

```bash
# Combined status overview
php bin/pulsar studio:console:guardian:status --json

# Run all guardian checks
php bin/pulsar studio:console:guardian:check --json

# Deploy checks via guardian
php bin/pulsar studio:console:guardian:deploy:check --env=production

# Integrity via guardian
php bin/pulsar studio:console:guardian:integrity:verify --strict --json
```

## Environment Variables

Key deployment environment variables:

| Variable            | Purpose                                             |
| ------------------- | --------------------------------------------------- |
| `APP_ENV`           | Environment mode (`local`, `staging`, `production`) |
| `APP_DEBUG`         | Debug mode (must be `false` in production)          |
| `PULSAR_MASTER_KEY` | Master encryption key (64-char hex)                 |
| `STUDIO_ENABLED`    | Enable Studio observability                         |
| `STUDIO_DISABLED`   | Force-disable Studio                                |
