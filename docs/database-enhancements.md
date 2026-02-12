# Database Enhancements

Pulsar's database layer provides production-grade connection management, routing, caching, failover, and monitoring for regulated workloads.

## Connection Pooling

Connection pooling is active **only in persistent runtimes** (Swoole, RoadRunner). Under PHP-FPM, connections are created per-request as usual.

### Configuration

```php
// config/database.php
'pool' => [
    'min_connections' => 2,
    'max_connections' => 10,
    'idle_timeout_seconds' => 60,
    'max_lifetime_seconds' => 3600,
    'health_check_interval_seconds' => 30,
],
```

### Behavior

- Connections are created lazily up to `max_connections`
- Idle connections are pruned after `idle_timeout_seconds`
- Connections exceeding `max_lifetime_seconds` are destroyed and replaced
- Health checks (`SELECT 1`) run on checkout when a connection has been idle longer than `health_check_interval_seconds`
- If the pool is exhausted, a `DatabaseException::poolExhausted()` is thrown
- Under FPM, `NullConnectionPool` creates fresh connections per checkout with no pooling overhead

## Read/Write Routing

Route SELECT queries to read replicas and writes to the primary.

### Configuration

```php
'read_write' => [
    'enabled' => true,
    'write_host' => 'primary.db.internal',
    'read_hosts' => ['replica-1.db.internal', 'replica-2.db.internal'],
    'sticky_duration' => 'request', // or milliseconds (e.g., 5000)
],
```

### Routing Rules

| Query Type                          | Routed To |
| ----------------------------------- | --------- |
| SELECT, SHOW, DESCRIBE, EXPLAIN     | Replica   |
| INSERT, UPDATE, DELETE, DDL         | Primary   |
| Any query inside a transaction      | Primary   |
| Any query after a write (sticky)    | Primary   |
| `->usePrimary()` override           | Primary   |
| `->useReplica()` override (audited) | Replica   |

### Primary Stickiness

After any write operation, all subsequent reads are pinned to the primary to prevent stale reads from replicas that haven't caught up.

- **Request-scoped** (default): Pinned until request ends or `resetRouting()` is called
- **Timed**: Pinned for N milliseconds, then resume routing

### Explicit Overrides

```php
$manager->usePrimary();   // Next query uses primary
$manager->useReplica();   // Next query uses replica (audited in regulated presets)
```

Both are single-query overrides. In regulated presets, `useReplica()` emits an audit event because it bypasses read-after-write consistency guarantees.

### Load Balancing

Multiple read replicas are selected via round-robin rotation.

## Failover

Pulsar detects primary failures and switches to a new endpoint. It does **not** promote replicas -- that is the infrastructure's responsibility (RDS Multi-AZ, Patroni, ProxySQL, etc.).

### Configuration

```php
'failover' => [
    'enabled' => true,
    'failure_threshold' => 3,
    'retry_interval_seconds' => 5,
    'strategy' => 'dns', // 'dns', 'callback', or 'config-reload'
    'compliance_events_enabled' => false,
],
```

### Strategies

| Strategy        | Description                                       |
| --------------- | ------------------------------------------------- |
| `dns`           | Re-resolve hostname via DNS lookup                |
| `callback`      | Invoke a cluster manager callback for new target  |
| `config-reload` | Hot-swap primary host from reloaded configuration |

### Circuit Breaker

When the primary is unavailable and no failover target is configured:

1. Circuit breaker opens after `failure_threshold` consecutive failures
2. Write operations are rejected
3. Read operations continue from replicas
4. Telemetry events emitted: `PrimaryUnavailable`, `CircuitBreakerOpened`
5. Circuit closes automatically when a health check succeeds

### Compliance Events

When `compliance_events_enabled` is true, failover emits a `FailoverEvent` with:

- Reason, source endpoint, target endpoint
- Affected operation count, duration
- Correlation ID and timestamp

## Query Cache

Cache query results via PSR-16 with tag-based invalidation.

### Configuration

```php
'query_cache' => [
    'enabled' => true,          // false by default in regulated preset
    'default_ttl_seconds' => 60,
    'sensitive_table_names' => ['audit_logs', 'encryption_keys'],
    'authorization_columns' => ['user_id', 'tenant_id'],
],
```

### Usage

Cache results for a specific duration:

```php
// Via the QueryCache directly
$cache->put($key, $result, ttlSeconds: 60, tags: ['users', 'orders']);
$cached = $cache->get($key);
```

### Cache Key Determinism

Cache keys are built from a hash of:

- Normalized SQL template
- Sorted and typed bindings
- Tenant ID
- Connection role (read/write)
- Schema version

This ensures no ambient state leaks into cache keys.

### Tag-Based Invalidation

- Cache entries are tagged by table name
- Write operations on a table invalidate all cached queries for that table
- Tags are extracted from the query builder AST (all referenced tables including JOINs)

### Safety Rules

- **Regulated preset**: Query caching disabled by default. Opt-in per query
- **Sensitive tables**: Never cached regardless of `->cache()` calls
- **Authorization-shaped queries**: Queries with `WHERE user_id = ?` or `WHERE tenant_id = ?` are excluded by default

## SQL Monitoring

### Safe SQL Logging

SQL logging never exposes sensitive data by default:

| What is logged    | Format                           |
| ----------------- | -------------------------------- |
| SQL template      | Normalized with `?` placeholders |
| Bindings          | xxh128 hash (never raw values)   |
| Duration          | Milliseconds                     |
| Row count         | Integer                          |
| Classification    | select/insert/update/delete/ddl  |
| Sensitivity level | From table metadata              |

### Production Enforcement

Raw binding logging requires **both**:

1. `log_raw_bindings: true` in config
2. `DB_LOG_RAW_BINDINGS=CONFIRM_UNSAFE` environment variable

Even in debug mode, fields classified as PII are masked with `***MASKED***`.

### Slow Query Detection

Queries exceeding the configured threshold (default: 1000ms) are logged as warnings with the normalized SQL, duration, and classification.

```php
'monitor' => [
    'slow_query_threshold_ms' => 1000,
    'pii_columns' => ['email', 'ssn', 'phone'],
],
```

### Connection Auditing

Connection lifecycle events are logged via PSR-3:

- **Connect**: Connection name and driver (info level)
- **Disconnect**: Connection name (info level)
- **Error**: Connection name and error message (error level)
- **Failover**: Source, target, and reason (warning level)

## Configuration Reference

All new configuration sections are optional with sensible defaults. Add them to `config/database.php` as needed:

```php
return [
    'default' => 'sqlite',
    'connections' => [/* ... */],
    'migrations' => [/* ... */],

    // New sections (all optional)
    'pool' => [/* ... */],
    'read_write' => [/* ... */],
    'failover' => [/* ... */],
    'query_cache' => [/* ... */],
    'monitor' => [/* ... */],
];
```
