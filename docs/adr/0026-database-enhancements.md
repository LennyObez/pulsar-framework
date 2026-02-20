# ADR-0023: Database Enhancements

- **Status**: Accepted
- **Date**: 2026-02-12
- **Plan**: RC11-12

## Context

Pulsar's database layer needs production-grade features for regulated workloads: connection pooling for persistent runtimes, read/write splitting with primary stickiness, failover detection, query caching with safety guarantees, and SQL logging that never leaks sensitive data.

CakePHP 5 offers read/write connection roles. Hyperf provides connection pooling. Both are standard in enterprise database architecture. Pulsar must match and exceed these capabilities while maintaining compliance-grade safety.

## Decision

### Connection pooling

Connection pooling is enabled only in persistent runtimes (Swoole, RoadRunner). Under FPM, `NullConnectionPool` degrades gracefully to per-request connections. The pool tracks idle timeouts, max lifetime, and periodic health checks via `SELECT 1`.

### Read/write routing

A `ReadWriteRouter` classifies SQL by first keyword to route queries. After any write, all reads pin to primary for configurable duration (default: request end). Within transactions, all queries go to primary unconditionally. Explicit `usePrimary()`/`useReplica()` overrides are single-query. In regulated presets, `useReplica()` overrides emit audit events.

### Failover

Failover is detection and switching, not promotion. Promotion is the infrastructure's responsibility. Three strategies are supported: DNS re-resolution, cluster manager callback, and configuration reload. A circuit breaker rejects writes when the primary is down and no failover target exists.

### Query cache

Query result caching wraps PSR-16 with deterministic cache keys (hash of normalized SQL + sorted bindings + tenant ID + role + schema version). Tag-based invalidation keyed by table name. Sensitive tables and authorization-shaped queries are never cached. Caching is disabled by default in the regulated preset.

### SQL Logging Safety

SQL logging uses normalized templates with `?` placeholders and binding hashes (xxh128). Raw bindings are never logged in production. Debug mode requires both a config flag and `DB_LOG_RAW_BINDINGS=CONFIRM_UNSAFE` environment variable. PII fields are masked even in debug mode.

## Consequences

- Connection pooling only benefits persistent runtimes; FPM users see no change
- Read/write routing adds a SQL classification step per query (negligible overhead: first-keyword parsing)
- Query cache requires PSR-16 implementation; tag storage adds cache entries for the tag index
- Failover detection adds periodic health checks; circuit breaker prevents cascading failures
- SQL logging is safe by default but requires explicit opt-in for debugging in production

## Alternatives considered

- **ORM-level caching**: Rejected. Too tightly coupled to query builder internals. PSR-16 wrapper is more portable.
- **Replica promotion in-app**: Rejected. Promotion is infrastructure's domain. Application should only detect and switch.
- **Always-on pooling**: Rejected. FPM's process-per-request model makes pooling counterproductive (connection state leaks, max_connections pressure).
