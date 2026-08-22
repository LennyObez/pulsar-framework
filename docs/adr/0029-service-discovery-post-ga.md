# ADR-0029: Service Discovery Architecture

- **Status**: Accepted
- **Date**: 2026-03-06
- **Plan**: RC11-25

## Context

Plan 25 specified a full service discovery and configuration center module for Pulsar. Regulated domains (banking, healthcare, legal) require reliable service topology management, health monitoring, and centralized configuration. The GA release ships a complete in-process implementation with well-defined contracts; dynamic backends (Consul, etcd, Kubernetes) are deferred to post-GA.

## Decision

Ship the following for 1.0.0 GA:

### Read-side (Discovery)

1. **`ServiceDiscoveryInterface`**: contract for service lookup: `instances()`, `instance()`, `register()`, `deregister()`, `services()`.
2. **`StaticServiceDiscovery`**: configuration-file-driven implementation with `fromArray()` factory. Registration and deregistration modify in-memory state only.
3. **`ServiceInstance`**: immutable value object (name, host, port, scheme, health status, metadata) with `uri()` helper.
4. **`ServiceHealthStatus`**: enum: Healthy, Degraded, Unhealthy, Unknown.

### Write-side (Registry)

5. **`ServiceRegistryInterface`**: lifecycle contract: `register()` with optional TTL, `deregister()`, `deregisterAll()`, `heartbeat()`, `updateHealth()`, `evictExpired()`.
6. **`InMemoryServiceRegistry`**: full registry implementing both `ServiceDiscoveryInterface` and `ServiceRegistryInterface` with TTL-based expiration, heartbeat refresh, health tracking, and event dispatch.
7. **`ServiceTtlEntry`** (Internal): TTL wrapper tracking registration time, heartbeat, and expiration.

### Health Checks

8. **`HealthCheckInterface`**: contract: `check(ServiceInstance): HealthCheckResult`.
9. **`HealthCheckResult`**: result DTO with `healthy()`, `unhealthy()`, `degraded()` static factories.
10. **`HttpHealthCheck`**: HTTP-based health check using configurable path and timeout.

### Configuration Center

11. **`ConfigCenterInterface`**: centralized config contract: `get()`, `set()`, `delete()`, `all()`, `has()`, `namespaces()`.
12. **`StaticConfigCenter`**: in-memory implementation with `fromArray()` factory.

### Events

13. **`ServiceRegistered`**: dispatched on registration.
14. **`ServiceDeregistered`**: dispatched on deregistration (reason: manual, bulk, ttl_expired).
15. **`ServiceHealthChanged`**: dispatched when health status transitions.

### Infrastructure

16. **`ServiceDiscoveryException`**: exception with static factories for common failure modes.
17. **`ServiceDiscoveryWiring`**: kernel wiring that registers all discovery services from configuration.

Dynamic backends (Consul, etcd, Kubernetes) are deferred to post-GA releases. The contracts are designed to accommodate them without breaking changes.

## Consequences

### Positive

- Complete in-process service discovery with TTL, health checks, and event-driven lifecycle: sufficient for production use in controlled environments.
- The stable `ServiceDiscoveryInterface` and `ServiceRegistryInterface` contracts enable applications to program against a fixed API surface. Post-GA dynamic backends will be backward-compatible additions.
- Event dispatch enables observability integration (metrics, audit logging) without coupling.
- `#[Api(since: '1.0.0')]` on all public types locks the API surface under semantic versioning guarantees.

### Negative

- Applications requiring distributed service discovery (auto-scaling, service meshes) must implement the interfaces against their preferred backend until official adapters ship.
- In-memory implementations lose state on process restart; persistent backends are post-GA.

### Neutral

- The interface design maps directly to Consul/etcd registration APIs, enabling straightforward adapter implementations.
- Third-party packages can provide discovery backends before official support ships.

## Timeline

Dynamic service discovery backends (Consul, etcd, Kubernetes) are targeted for 1.1.0 or 1.2.0, prioritized based on community demand and production feedback from GA adopters.
