# ADR-0018: Application Cache Layer

## Status

Accepted

## Context

Regulated applications in banking, healthcare, and legal domains need caching infrastructure that goes beyond basic key-value storage. Requirements include multi-driver support for different deployment topologies, tag-based invalidation for surgical cache clearing, stampede protection to prevent thundering herd on cache misses, optional encryption-at-rest for sensitive cached data, and full observability through metrics and event emission.

The cache layer must conform to PSR-6 (CacheItemPool) and PSR-16 (SimpleCache) for interoperability, while providing application-level features that neither PSR defines: tags, stampede guards, encryption, distributed locking, and driver capability negotiation.

## Decision Drivers

1. **Dual PSR compliance**: Applications and libraries expect PSR-6 and PSR-16. Both must be first-class, not one wrapping the other with impedance mismatch.
2. **Driver abstraction**: Deployment environments vary - local development uses filesystem or array, staging uses APCu, production uses Memcached or Redis. The application layer must be driver-agnostic.
3. **Compliance**: Regulated domains may require encryption-at-rest for cached PII. The cache layer must support transparent encryption without application code changes.
4. **Stampede protection**: High-traffic cache key expiration causes thundering herd. The framework must provide built-in protection, not leave it to application developers.
5. **Observability**: Cache hit/miss ratios, error rates, and latency must feed into the framework's metric and logging infrastructure.

## Decision

Implement `src/Cache/Application/` as the application cache module with the following architecture:

### Dual PSR Implementation

- `CachePool` implements PSR-6 `CacheItemPoolInterface` with deferred save support, `remember()` convenience method, and critical mode (throw on driver failure vs. silent degradation)
- `SimpleCache` implements PSR-16 `CacheInterface` by wrapping `CachePool`, providing the simpler API for common use cases

### Driver Layer

All drivers implement `CacheDriverInterface` - a raw string storage contract. Serialization happens in the pool layer, keeping drivers simple and testable.

| Driver             | Use Case                         | Capabilities                    |
| ------------------ | -------------------------------- | ------------------------------- |
| `ArrayDriver`      | Testing, short-lived processes   | Full (in-memory)                |
| `FilesystemDriver` | Local development, single-server | Persistent, no atomic increment |
| `ApcuDriver`       | Single-server, shared-nothing    | Fast, atomic increment          |
| `MemcachedDriver`  | Distributed, multi-server        | Distributed, atomic increment   |
| `RedisDriver`      | Distributed, feature-rich        | Distributed, atomic increment   |
| `DatabaseDriver`   | Persistent, no external service  | SQL-backed, no atomic increment |

Drivers declare capabilities via `CacheDriverCapabilities` - a value object indicating support for atomic increment, distributed locking, and TTL precision. Upper layers query capabilities before attempting unsupported operations.

### Tag-Based Invalidation

`TaggedCache` stores tag version snapshots with each cached item. On read, current tag versions are compared against stored versions - stale items are treated as cache misses. Tag invalidation bumps the version rather than scanning and deleting individual keys, making invalidation O(1) per tag regardless of how many items share that tag.

Two tag strategies are provided:

- `StrictTagStrategy` - atomic tag version reads/writes via the cache driver (consistent but slower)
- `BestEffortTagStrategy` - eventual consistency with local version caching (faster but may serve briefly stale data)

### Stampede Protection

`StampedeGuard` implements a lock-based get-or-compute pattern:

1. On cache miss, acquire a lock for the key
2. Double-check the cache (another process may have regenerated)
3. Invoke the computation callback
4. Store the result with TTL jitter (randomized ±10% to prevent synchronized expiration)
5. Release the lock

On lock timeout, the guard retries the cache read (optimistic path) and falls back to direct callback invocation (degraded path).

### Encryption at Rest

`EncryptedCacheDecorator` wraps any `CacheDriverInterface` with transparent encryption using libsodium secretbox (XSalsa20-Poly1305, ADR-0006). Features:

- BLAKE2b HMAC binds ciphertext to pool name, cache key, tenant ID, and purpose - preventing cross-pool ciphertext relocation attacks
- Transparent key rotation: values encrypted under the previous master key are decrypted and re-encrypted on read
- Atomic increment/decrement is explicitly unsupported on encrypted pools (throws `UnsupportedCapabilityException`)

### Distributed Locking

Lock implementations parallel the driver layer: `ApcuLock`, `MemcachedLock`, `RedisLock`, `FilesystemLock`, `DatabaseLock`, `ArrayLock`. All implement `LockInterface` with acquire/release semantics and configurable TTL. `FencedExecutor` provides fenced token support for safe lock extension in long-running operations.

### Observability

`CacheEventEmitter` bridges cache operations to `MetricRegistry` and `LoggerInterface`:

- Counters: `cache_hits_total`, `cache_misses_total`, `cache_writes_total`, `cache_deletes_total`, `cache_errors_total`
- Labels: pool name, driver name, cache key
- Events: `CacheHitEvent`, `CacheMissEvent`, `CacheWriteEvent`, `CacheDeleteEvent`, `CacheClearEvent`, `CacheErrorEvent`

### Key Validation

`CacheKeyValidator` enforces PSR-6 reserved character rules plus configurable maximum key length and allowed character sets. Validation runs on every public API entry point.

### Configuration

`CacheConfig` follows the readonly DTO pattern (ADR-0011) with `fromArray()` factory. Configures default driver, TTL, pool definitions, encryption settings, and stampede guard parameters.

## Alternatives Considered

### Third-party cache library

Rejected: adds a runtime dependency for infrastructure code that touches every cached value. Version conflicts and abandoned upstream maintenance are unacceptable risks for a foundational layer.

### Plain PSR-6 without application layer

Rejected: PSR-6 defines the pool contract but not tags, stampede protection, encryption, or observability. Applications would build these features ad-hoc, leading to inconsistent implementations.

### Single-driver approach (e.g., Redis-only)

Rejected: not all deployment environments have Redis. Local development, CI, and air-gapped production environments need filesystem or in-memory alternatives. The driver abstraction costs minimal overhead.

## Consequences

### Positive

- Dual PSR-6/PSR-16 compliance ensures interoperability with third-party libraries
- Tag-based invalidation provides surgical cache clearing without key enumeration
- Stampede protection is built into the framework, not left to application developers
- Transparent encryption meets compliance requirements without application code changes
- Driver capability negotiation prevents runtime errors from unsupported operations

### Negative

- Seven drivers and six lock implementations add maintenance surface (~20 classes in `Driver/` and `Lock/`)
- Tag version storage adds overhead to every tagged cache read (one additional driver round-trip per unique tag set)
- Encrypted pools cannot support atomic increment/decrement - a fundamental limitation of authenticated encryption

### Neutral

- `SimpleCache` wraps `CachePool` - the PSR-16 API has slightly higher overhead than direct driver access (one extra method call layer)
- Critical mode is opt-in per pool - non-critical pools silently degrade on driver failure, which may mask infrastructure issues if monitoring is not configured

## Security Impact

- `EncryptedCacheDecorator` uses framework crypto (libsodium, ADR-0006) - no custom cryptographic implementations
- AAD binding prevents ciphertext relocation between pools, keys, tenants, or purposes
- Key rotation is transparent - old ciphertexts are re-encrypted on read without application intervention
- `CacheKeyValidator` prevents injection via cache keys (reserved characters, length limits)
- Critical mode ensures security-sensitive cache failures (e.g., encrypted session store) surface as exceptions rather than silent degradation

## Performance Impact

- Driver operations are I/O-bound. The application layer adds serialization (PHP serialize or JSON), key validation, and event emission - all sub-microsecond on modern hardware.
- Tag validation adds one `getTagVersions()` call per tagged read. `BestEffortTagStrategy` caches versions locally to amortize this cost.
- Stampede guard lock acquisition adds one round-trip to the lock backend on cache miss. The jittered TTL prevents synchronized expiration across distributed instances.
- Encryption adds ~50μs per operation (libsodium secretbox). Acceptable for most use cases; benchmark-sensitive paths should use unencrypted pools.

## Migration / Rollback Plan

Additive change - introduces `src/Cache/Application/` as a new core module. To roll back: remove the module and revert to direct PSR-6/PSR-16 library usage. Cache data is ephemeral by nature - no data migration required.

## Links

- ADR-0006: Libsodium-only crypto and master key derivation
- ADR-0007: In-house observability stack
- ADR-0011: Typed readonly configuration DTOs
- ADR-0016: Container dependency injection (driver/lock wiring)
