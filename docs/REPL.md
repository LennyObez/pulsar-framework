# Interactive REPL (`pulsar shell`)

Pulsar ships with an interactive PHP REPL powered by [PsySH](https://psysh.org/) that gives you a live shell with full framework context — container access, configuration, services, and more.

## Installation

PsySH is a suggested dependency. Install it as a dev dependency:

```bash
composer require --dev psy/psysh
```

## Enabling the REPL

The REPL is **disabled by default** in all environments for security. Enable it by setting the `REPL_ENABLED` environment variable:

```dotenv
# .env
REPL_ENABLED=true
```

Or in `config/repl.php`:

```php
return [
    'enabled' => true,
];
```

The environment variable takes precedence over the config file value.

## Usage

```bash
php bin/pulsar shell
```

This starts an interactive session with:

- `$container` — the framework DI container
- `$redactor` — the `SecretRedactor` instance (when available) for scrubbing sensitive output

### Options

| Option                   | Description                                               |
| ------------------------ | --------------------------------------------------------- |
| `--no-safe-mode`         | Disable safe mode (allow database writes, queue dispatch) |
| `--i-know-what-im-doing` | Required for production REPL access                       |
| `--no-audit`             | Disable audit logging for this session                    |

## Safe Mode

By default, the REPL starts in **safe mode** which wraps core services with read-only decorators:

| Service             | Allowed                                                                                 | Blocked                                                    |
| ------------------- | --------------------------------------------------------------------------------------- | ---------------------------------------------------------- |
| Database connection | SELECT, EXPLAIN, DESCRIBE, SHOW, PRAGMA, WITH (read-only CTEs), prepare (read-only SQL) | INSERT, UPDATE, DELETE, DDL, transactions, execute         |
| Queue driver        | size, findByStatus                                                                      | push (captured only), pop, acknowledge, reject, purge      |
| Storage adapter     | get, exists, list, temporaryUrl                                                         | put, delete                                                |
| PSR-16 SimpleCache  | get, getMultiple, has                                                                   | set, setMultiple, delete, deleteMultiple, clear            |
| PSR-6 CachePool     | getItem, getItems, hasItem                                                              | save, saveDeferred, commit, deleteItem, deleteItems, clear |

Blocked operations throw `ReplSafeModeException` with a clear message.

To disable safe mode:

```bash
php bin/pulsar shell --no-safe-mode
```

## Environment Guards

The REPL enforces environment-based access controls:

| Environment | Requirements                                              |
| ----------- | --------------------------------------------------------- |
| CI          | Always blocked (detects `CI` env var)                     |
| Production  | Requires `REPL_ENABLED=true` AND `--i-know-what-im-doing` |
| Staging     | Requires `REPL_ENABLED=true`                              |
| Local       | Requires `REPL_ENABLED=true`                              |

## Audit Logging

When audit logging is enabled (`audit => true` in config), the REPL logs session lifecycle events:

- **Session start** — actor, environment, safe mode status
- **Session end** — actor, duration (logged even if the shell crashes, via try/finally)
- **Production overrides** — when `--i-know-what-im-doing` is used

Disable audit for a session with `--no-audit`.

## Secret Redaction

The `SecretRedactor` protects sensitive data in REPL output. When available, it is exposed as the `$redactor` scope variable:

```php
// In the REPL:
echo $redactor->redactOutput($someString);
$dump = $redactor->redactObjectDump($someObject);
$clean = $redactor->scrubArray($someArray);
```

Redaction strategies:

- **Known secrets** — registered values are replaced with `********`
- **DSN credentials** — `://user:password@host` patterns are redacted
- **`#[Sensitive]` properties** — object properties annotated with `#[Sensitive]` are redacted in dumps
- **Array scrubbing** — delegates to `SensitiveDataScrubber` for key-based redaction (password, token, secret, etc.)

### Marking Properties as Sensitive

```php
use Pulsar\Attribute\Sensitive;

readonly class DatabaseCredentials
{
    public function __construct(
        public string $host,
        #[Sensitive(reason: 'Database password')]
        public string $password,
        #[Sensitive]
        public string $apiKey,
    ) {}
}
```

## Configuration Reference

`config/repl.php`:

```php
return [
    // Enable the REPL (override with REPL_ENABLED env var)
    'enabled' => false,

    // Wrap services with read-only decorators
    'safe_mode' => true,

    // Log session lifecycle events via audit logger
    'audit' => false,

    // Command history file path (relative to project root)
    'history_file' => '.pulsar_repl_history',

    // PHP statements to run on session start
    'startup_commands' => [],
];
```
