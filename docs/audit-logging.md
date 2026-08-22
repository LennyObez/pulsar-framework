# Audit logging

Pulsar provides a tamper-evident audit logging subsystem separate from general application logging. Audit logs record security-relevant events (authentication, authorization, data access) with HMAC-chained integrity guarantees.

## Why separate from general logging?

General logging (`Pulsar\Observability\Log\Logger`) records operational events: errors, warnings, debug information. Audit logging records **who did what, when, and why** for compliance and forensic purposes. The two have different:

- **Schemas** - audit entries have structured fields (actor, action, resource, outcome)
- **Integrity guarantees** - audit entries are HMAC-chained; tampering with any entry invalidates all subsequent entries
- **Retention policies** - audit logs are typically retained longer and may have legal requirements
- **Access controls** - audit logs should be write-once, append-only

## Architecture

```
AuditLogger
  ├── Creates AuditEntry (with HMAC chain)
  ├── Writes to AuditSinkInterface
  └── Advances previousHmac state

AuditEntry (readonly VO)
  ├── id, event, outcome, actor, action, resource, timestamp, metadata
  ├── hmac (computed over all fields + previousHmac)
  └── verify(auditKey) → bool

AuditSinkInterface
  └── AuditFileSink (append-only JSON Lines with LOCK_EX)
```

## Configuration

Audit logging is configured under the `audit` key in `config/observability.php`:

```php
// config/observability.php
return [
    // ... logging config ...
    'audit' => [
        'enabled'  => true,
        'log_path' => 'var/logs/audit.jsonl',
        'events'   => ['*'],  // Log all event types, or specify: ['authentication', 'authorization']
    ],
];
```

Environment variable overrides:

| Variable         | Overrides        |
| ---------------- | ---------------- |
| `AUDIT_LOG_PATH` | `audit.log_path` |

## Events and outcomes

### AuditEvent

```php
enum AuditEvent: string
{
    case Authentication     = 'authentication';
    case Authorization      = 'authorization';
    case DataAccess         = 'data_access';
    case DataModification   = 'data_modification';
    case ConfigurationChange = 'configuration_change';
    case SecurityEvent      = 'security_event';
    case SystemEvent        = 'system_event';
}
```

### AuditOutcome

```php
enum AuditOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Denied  = 'denied';
    case Error   = 'error';
}
```

## Usage

### Basic logging

```php
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

$auditLogger->log(
    event: AuditEvent::Authentication,
    outcome: AuditOutcome::Success,
    actor: 'user:42',
    action: 'login',
    resource: 'session',
    metadata: ['ip' => '192.168.1.1', 'method' => 'password'],
);
```

### Common patterns

**Authentication events:**

```php
// Successful login
$auditLogger->log(
    event: AuditEvent::Authentication,
    outcome: AuditOutcome::Success,
    actor: "user:{$userId}",
    action: 'login',
    resource: 'session',
);

// Failed login attempt
$auditLogger->log(
    event: AuditEvent::Authentication,
    outcome: AuditOutcome::Failure,
    actor: "email:{$email}",
    action: 'login',
    resource: 'session',
    metadata: ['reason' => 'invalid_credentials'],
);
```

**Authorization events:**

```php
// Access denied
$auditLogger->log(
    event: AuditEvent::Authorization,
    outcome: AuditOutcome::Denied,
    actor: "user:{$userId}",
    action: 'view',
    resource: "document:{$docId}",
    metadata: ['required_role' => 'admin'],
);
```

**Data access events:**

```php
// Sensitive data accessed
$auditLogger->log(
    event: AuditEvent::DataAccess,
    outcome: AuditOutcome::Success,
    actor: "user:{$userId}",
    action: 'export',
    resource: 'patient_records',
    metadata: ['record_count' => 150, 'format' => 'csv'],
);
```

## HMAC chain (tamper evidence)

Each audit entry includes an HMAC computed over all its fields plus the previous entry's HMAC. This creates a chain where modifying or deleting any entry invalidates all subsequent entries.

### How it works

1. **Seed**: The chain starts with a seed HMAC: `Hmac::computeHex('PULSAR_AUDIT_SEED', auditKey)`
2. **Entry HMAC**: Each entry's HMAC covers: `id | event | outcome | actor | action | resource | timestamp | metadata_json | previousHmac`
3. **Chain advancement**: After writing an entry, its HMAC becomes the `previousHmac` for the next entry

### Key derivation

The audit key is derived from the master key using libsodium's KDF:

```
MasterKey (PULSAR_MASTER_KEY env)
  └── deriveSubKey(subKeyId: 2, context: 'audit___')
        └── auditKey (32 bytes)
```

### Verification

Each entry can independently verify its own HMAC given the audit key and the previous entry's HMAC:

```php
$entry = AuditEntry::create(
    event: AuditEvent::Authentication,
    outcome: AuditOutcome::Success,
    actor: 'user:42',
    action: 'login',
    resource: 'session',
    metadata: [],
    previousHmac: $previousHmac,
    auditKey: $auditKey,
);

$valid = $entry->verify($auditKey);  // true
```

### Chain verification

To verify the entire audit log, replay entries in order:

1. Compute the seed HMAC
2. For each entry, verify its HMAC against the expected `previousHmac`
3. If any entry fails, the chain is broken at that point - indicating tampering

## AuditEntry

Immutable value object representing a single audit log entry.

### Fields

| Field          | Type           | Description                                |
| -------------- | -------------- | ------------------------------------------ |
| `id`           | `string`       | UUIDv4 identifier                          |
| `event`        | `AuditEvent`   | Category of the event                      |
| `outcome`      | `AuditOutcome` | Result of the action                       |
| `actor`        | `string`       | Who performed the action (e.g., `user:42`) |
| `action`       | `string`       | What was done (e.g., `login`, `export`)    |
| `resource`     | `string`       | What was acted upon (e.g., `session`)      |
| `timestamp`    | `string`       | ISO 8601 with microseconds                 |
| `metadata`     | `array`        | Additional context (key-value pairs)       |
| `hmac`         | `string`       | keyed BLAKE2b hex covering all fields      |
| `previousHmac` | `string`       | Previous entry's HMAC (chain link)         |

### Serialization

```php
$array = $entry->toArray();
// [
//     'id'            => 'a1b2c3d4-...',
//     'event'         => 'authentication',
//     'outcome'       => 'success',
//     'actor'         => 'user:42',
//     'action'        => 'login',
//     'resource'      => 'session',
//     'timestamp'     => '2026-02-03T12:00:00.123456+00:00',
//     'metadata'      => ['ip' => '192.168.1.1'],
//     'hmac'          => '5a3f...',
//     'previous_hmac' => 'b7e2...',
// ]
```

## AuditFileSink

Append-only file sink writing JSON Lines format with exclusive locking.

### Behavior

- Creates the target directory with `0750` permissions if it does not exist
- Each entry is written as a single JSON line followed by a newline
- Uses `LOCK_EX` for atomic writes (safe for single-process deployments)
- Throws `SecurityException::auditWriteFailed()` on write failure

### File format

Each line is a self-contained JSON object:

```jsonl
{"id":"a1b2...","event":"authentication","outcome":"success","actor":"user:42","action":"login","resource":"session","timestamp":"2026-02-03T12:00:00.123456+00:00","metadata":{"ip":"192.168.1.1"},"hmac":"5a3f...","previous_hmac":"b7e2..."}
{"id":"c3d4...","event":"data_access","outcome":"success","actor":"user:42","action":"view","resource":"report:7","timestamp":"2026-02-03T12:00:01.234567+00:00","metadata":{},"hmac":"9f1a...","previous_hmac":"5a3f..."}
```

## AuditLogger

Orchestrates audit entry creation, HMAC chain management, and sink writing.

### Constructor

```php
$auditLogger = new AuditLogger(
    sink: $auditFileSink,
    auditKey: $auditKey,    // 32-byte derived key
);
```

### Lifecycle

1. On construction, computes the seed HMAC: `Hmac::computeHex('PULSAR_AUDIT_SEED', auditKey)`
2. On each `log()` call:

- Creates an `AuditEntry` with the current `previousHmac`
- Writes the entry to the sink
- Updates `previousHmac` to the new entry's HMAC

3. The chain is maintained in memory for the lifetime of the `AuditLogger` instance

## Kernel registration

When `PULSAR_MASTER_KEY` is set, the kernel automatically:

1. Loads the master key via `MasterKey::fromEnvironment()`
2. Derives the audit subkey: `masterKey->deriveSubKey(2, 'audit___')`
3. Creates `AuditFileSink` with the configured log path
4. Creates `AuditLogger` with the sink and derived key
5. Registers `AuditLogger` in the container

If `PULSAR_MASTER_KEY` is not set, `AuditLogger` is not registered. Application code should check container availability before using it.

## Security considerations

- **Key protection**: The `PULSAR_MASTER_KEY` must be kept secret. If compromised, an attacker could forge valid audit entries.
- **Append-only storage**: Use filesystem permissions and/or immutable storage to prevent direct file modification.
- **Chain gaps**: If the application restarts, the HMAC chain restarts from the seed. For continuous chain verification across restarts, persist the last HMAC.
- **Clock integrity**: Timestamps use the system clock. Use NTP to ensure accurate, monotonic timestamps.
- **Distributed deployments**: `AuditFileSink` uses `LOCK_EX` for single-process safety. For multi-process or distributed deployments, implement a custom `AuditSinkInterface` backed by a database or message queue.
