# ADR-0022: Interactive REPL Shell

## Status

Accepted

## Context

Developers debugging regulated applications need an interactive shell with full framework context - container services, database connections, cache pools, queue drivers. However, production REPL access is a significant security risk: an unsandboxed shell can modify data, dispatch jobs, and access secrets. The shell must provide safe-mode sandboxing that blocks mutations, mandatory audit logging in production, secret redaction in output, and environment guards that prevent accidental production access.

## Decision drivers

1. **Security by default**: Production REPL access is blocked unless explicitly overridden with both configuration and a CLI flag. CI environments are always blocked.
2. **Safe mode**: In regulated domains, read-only inspection is the primary REPL use case. Mutations (database writes, queue dispatch, cache writes, storage writes) must be blockable.
3. **Audit trail**: Production REPL sessions must be logged with actor identity, session duration, and production override acknowledgement. Audit logging cannot be disabled in production.
4. **Secret protection**: REPL output must not expose database passwords, API keys, or other sensitive values - even when inspecting container services or configuration objects.
5. **No compile-time coupling**: The REPL shell (PsySH) is a suggested dependency, not a required one. The framework must not break if PsySH is not installed.

## Decision

Implement `src/Console/Repl/` as the interactive shell module with the following architecture:

### Shell command

`ShellCommand` extends the framework console `Command` and orchestrates the REPL lifecycle:

1. Check PsySH availability (`class_exists('Psy\\Shell')`) - fail gracefully with install instructions if absent
2. Run `EnvironmentGuard::canStart()` - enforce environment and configuration checks
3. Apply `SafeModeProvider` wrappers if safe mode is enabled
4. Start `ReplAuditLogger` session logging
5. Build scope variables (container, redactor) and launch PsySH
6. Log session end in a `finally` block (always, even on exception)

PsySH classes are instantiated via string-based dynamic resolution to avoid compile-time coupling:

```php
$configClass = 'Psy\\Configuration';
$shellClass = 'Psy\\Shell';
$shell = new $shellClass(new $configClass([...]));
```

### Environment guard

`EnvironmentGuard` enforces a three-tier access policy:

| Environment | Config Enabled | Force Flag               | Result                                  |
| ----------- | -------------- | ------------------------ | --------------------------------------- |
| CI          | Any            | Any                      | Blocked (always)                        |
| Production  | `false`        | Any                      | Blocked                                 |
| Production  | `true`         | `--i-know-what-im-doing` | Allowed (logged as production override) |
| Production  | `true`         | Not set                  | Blocked                                 |
| Other       | `false`        | Any                      | Blocked                                 |
| Other       | `true`         | Any                      | Allowed                                 |

The guard returns a `GuardResult` value object with `allowed`, `reason`, and `isProductionOverride` fields - enabling the shell command to log overrides and display actionable error messages.

### Safe mode

`SafeModeProvider` replaces mutable container bindings with read-only decorators:

| Service                        | Wrapper                  | Behavior                                               |
| ------------------------------ | ------------------------ | ------------------------------------------------------ |
| Database `ConnectionInterface` | `ReadOnlyConnection`     | Blocks `execute()`, `insert()`, `update()`, `delete()` |
| `QueueDriverInterface`         | `CaptureOnlyQueueDriver` | Captures dispatched jobs without sending               |
| `StorageAdapterInterface`      | `ReadOnlyStorageAdapter` | Blocks `write()`, `delete()`, `move()`                 |
| PSR-16 `CacheInterface`        | `ReadOnlySimpleCache`    | Blocks `set()`, `delete()`, `clear()`                  |
| PSR-6 `CacheItemPoolInterface` | `ReadOnlyCachePool`      | Blocks `save()`, `deleteItem()`, `clear()`             |

`ReadOnlyContainer` wraps the container itself, preventing `bind()`, `instance()`, and `forgetInstance()` calls - ensuring safe mode cannot be circumvented by re-binding services.

All wrappers throw `ReplSafeModeException` on mutation attempts with a clear message identifying the blocked operation.

### Secret redaction

`SecretRedactor` applies four redaction strategies:

1. **Known secret values**: Registered via `addSecretValue()` - exact string replacement for values loaded from environment/config
2. **DSN credentials**: Regex-based scrubbing of `://user:password@host` patterns in output
3. **`#[Sensitive]` properties**: Object dump redaction - properties annotated with `#[Sensitive]` are replaced with `********`
4. **Framework scrubber**: Array key scrubbing via `SensitiveDataScrubber` for keys matching patterns like `password`, `secret`, `token`, `api_key`

### Audit logging

`ReplAuditLogger` wraps the framework `AuditLoggerInterface` with REPL-specific events:

| Event               | Audit Type      | Logged Data                         |
| ------------------- | --------------- | ----------------------------------- |
| Session start       | `SystemEvent`   | Actor, environment, safe mode flag  |
| Session end         | `SystemEvent`   | Actor, duration, command count      |
| Command execution   | `DataAccess`    | Actor, command text, result summary |
| Production override | `SecurityEvent` | Actor, session ID                   |

Result summaries are type descriptions with truncated previews (`object(App\User)`, `array(42)`, `string(Hello...)`) - never full dumps.

### Configuration

`ReplConfig` follows the readonly DTO pattern (ADR-0011): `safeMode` (default true), `audit` (default true), `historyFile`, `startupCommands`.

## Alternatives considered

### Custom eval loop

Rejected: reimplementing a REPL with readline support, syntax highlighting, tab completion, and error handling is substantial engineering effort. PsySH provides all of this as a suggested dependency - the framework adds the security/audit layer on top.

### Browser-based console

Rejected: browser consoles expose a network-accessible eval endpoint - an unacceptable attack surface for regulated applications. CLI-only access limits exposure to authenticated shell sessions.

### Standalone CLI debugger (no framework context)

Rejected: the primary value of a framework REPL is access to the application container, configured services, and database connections. A standalone debugger without framework context provides minimal advantage over `php -a`.

## Consequences

### Positive

- Safe mode prevents accidental data mutation during debugging sessions
- Environment guard with explicit production override flag prevents accidental production REPL access
- Audit logging provides compliance-grade session records for regulated environments
- Secret redaction prevents credential exposure through REPL output
- No compile-time coupling to PsySH - framework builds and runs without it

### Negative

- Safe mode wrappers must be maintained for each mutable service type - new service interfaces require new wrappers
- Dynamic PsySH instantiation (`new $shellClass(...)`) loses static analysis coverage for the shell configuration
- Audit logging does not capture individual PsySH command inputs without PsySH instrumentation hooks (command count is unavailable)

### Neutral

- Actor identity is resolved from `$_SERVER['USER']`/`$_SERVER['USERNAME']` (OS username) - sufficient for audit trails but not authenticated identity
- Safe mode is applied by replacing container bindings - services already resolved before safe mode activation retain mutable references

## Security impact

The REPL is the highest-risk developer tool in a regulated framework. Mitigations:

- **Defense in depth**: Three independent barriers - config flag, environment guard, force flag - must all be satisfied for production access
- **CI always blocked**: No configuration can enable REPL in CI environments, preventing accidental pipeline exposure
- **Mandatory production audit**: `--no-audit` is silently ignored in production. All production sessions are logged.
- **Safe mode default**: Enabled by default. Developers must explicitly pass `--no-safe-mode` to enable mutations.
- **Secret redaction**: Four-strategy defense prevents credential exposure through REPL output
- **No network exposure**: CLI-only - no HTTP endpoint, no WebSocket listener, no remote access

## Performance impact

No impact on application performance. The REPL module is only loaded when the `shell` console command is invoked. Safe mode wrappers are applied once at session start. Audit logging adds one log entry per session start/end - negligible.

## Migration / rollback plan

Additive change - introduces `src/Console/Repl/` as a new module. To roll back: remove the module and the `shell` console command registration. No data migrations required. Audit logs are written through the standard `AuditLoggerInterface` and persist independently.

## Links

- ADR-0006: Libsodium-only crypto (secret redaction patterns)
- ADR-0008: HMAC-chained tamper-evident audit logging
- ADR-0011: Typed readonly configuration DTOs (`ReplConfig`)
- ADR-0016: Container dependency injection (`ReadOnlyContainer`)
- ADR-0018: Application cache layer (read-only cache wrappers)
