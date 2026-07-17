# ADR-0018: Application Cache Layer

## Status

Accepted

## Context

Regulated applications in banking, healthcare, and legal domains need caching infrastructure that goes beyond basic key-value storage. Requirements include multi-driver support for different deployment topologies, tag-based invalidation for surgical cache clearing, stampede protection to prevent thundering herd on cache misses, optional encryption-at-rest for sensitive cached data, and full observability through metrics and event emission.

The cache layer must conform to PSR-6 (CacheItemPool) and PSR-16 (SimpleCache) for interoperability, while providing application-level features that neither PSR defines: tags, stampede guards, encryption, distributed locking, and driver capability negotiation.

## Decision drivers

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

### Driver layer

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

### Tag-based invalidation

`TaggedCache` stores tag version snapshots with each cached item. On read, current tag versions are compared against stored versions - stale items are treated as cache misses. Tag invalidation bumps the version rather than scanning and deleting individual keys, making invalidation O(1) per tag regardless of how many items share that tag.

Two tag strategies are provided:

- `StrictTagStrategy` - atomic tag version reads/writes via the cache driver (consistent but slower)
- `BestEffortTagStrategy` - eventual consistency with local version caching (faster but may serve briefly stale data)

### Stampede protection

`CachePool::remember()` implements a lock-based get-or-compute pattern, reusing
the pool's own `LockInterface` backend so no separate lock topology is needed:

1. On cache miss, acquire a per-key lock (`_stampede.<key>`)
2. Double-check the cache (another process may have regenerated)
3. Invoke the computation callback
4. Store the result with TTL jitter (randomized, up to `stampede_jitter_factor`
   of the TTL, to prevent synchronized expiration)
5. Release the lock

On lock timeout, `remember()` polls the cache for the winner's write for a
bounded window (`LOSER_POLL_WINDOW_MS`) before falling back to a direct,
unlocked compute — otherwise every loser that times out at the same instant
would recompute at once, the exact herd the lock prevents, whenever a
regeneration outlasts the lock timeout. The poll is bounded so a request never
hangs on a dead winner. The primary tuning lever is the per-pool
`stampede_lock_timeout_ms` (default 5000) and `stampede_lock_ttl_seconds`
(default 30): the invariant is `stampede_lock_timeout_ms > p99 regeneration`
(so a loser reads the winner's write rather than recomputing) and
`stampede_lock_ttl_seconds > timeout + p99` (so the lock outlives a legitimate
render). All reads and writes flow through the pool's `getItem()`/`save()`, so
stampede-protected regeneration still emits the normal hit/miss/write/error
events and honours critical mode.

Protection is on by default and reuses the pool's lock backend; set
`stampede_protection => false` on a pool to make `remember()` a plain
get-or-compute (e.g. for the in-process `array` driver where a single worker
never stampedes itself).

### Encryption at rest

`EncryptedCacheDecorator` wraps any `CacheDriverInterface` with transparent encryption using libsodium secretbox (XSalsa20-Poly1305, ADR-0006). Features:

- keyed BLAKE2b binds ciphertext to pool name, cache key, tenant ID, and purpose - preventing cross-pool ciphertext relocation attacks
- Transparent key rotation: values encrypted under the previous master key are decrypted and re-encrypted on read
- Atomic increment/decrement is explicitly unsupported on encrypted pools (throws `UnsupportedCapabilityException`)

### Value compression (opt-in)

`CompressingCacheDecorator` wraps any `CacheDriverInterface` with transparent
value compression (`compression: 'auto' | 'zstd' | 'zlib'`, default off;
`compression_threshold_bytes`, default 4096). `auto` negotiates zstd > zlib;
zlib is the floor because ext-zlib is a hard requirement, so `auto` always
resolves to a loadable codec. lz4 was offered here and has been withdrawn: it
trades ratio for throughput at a scale this layer never reaches (a cache value
sits behind a driver round-trip that dwarfs the codec by two orders of
magnitude), so zstd dominates it end-to-end — its worse ratio actively costs
more bytes on the wire than its speed saves. Its envelope byte (`0x03`) stays
permanently reserved so the algorithm space remains append-only. The stored form is
self-describing (a four-byte magic plus an algorithm byte), so compressed and
raw entries coexist: enabling, disabling, or switching algorithms never
invalidates existing entries. Values below the threshold or that do not shrink
are stored raw. Counters bypass compression entirely — `increment()`/
`decrement()` delegate untransformed.

### Key prefixing (opt-in)

`PrefixedCacheDecorator` namespaces every data key under the pool `prefix`
(charset `[A-Za-z0-9_.:-]`, max 64 — no glob metacharacters, so Redis `SCAN
MATCH` patterns stay literal). Redis and Memcached store keys raw and memoize
connections per host:port, so without a prefix all pools — and all applications
— on one backend share a single keyspace, and `clear()` (`FLUSHDB` / `flush`)
wipes everything. With a prefix, `clear()` becomes an exact prefix-scoped
deletion on drivers that can enumerate keys (`PrefixClearableInterface`: Redis
cursor-based SCAN+UNLINK, APCu iterator, the in-memory array driver) and FAILS
LOUDLY on drivers that cannot (Memcached has no enumeration primitive) rather
than silently flushing beyond its scope. The boot wiring warns when a
Redis/Memcached pool has no prefix.

Lock resources are prefixed too: `CacheManager::lock()` wraps the resolved lock
in a `PrefixedLock` when the pool has a prefix, so `CachePool`'s stampede lock
and any consumer of `CacheManager::lock()` (the CMS page single-flight) namespace
their resources the same way the data keys do — two pools or applications
sharing one backend no longer contend on the same logical lock. On Memcached the
prefix also shrinks the effective key budget (250-byte server limit minus the
prefix length).

Decorator stacking order in `CacheManager::driver()` is load-bearing:

```
caller → Prefix → Compression → Encryption → concrete driver
```

The prefix sits OUTERMOST so the encryption decorator binds the FINAL
(prefixed) storage key into its AAD: a ciphertext written under one prefix
cannot be transplanted to the same logical key under another prefix sharing a
backend and master key.

Compression sits ABOVE encryption because ciphertext is incompressible
(compress-then-encrypt). That combination leaks plaintext structure through
ciphertext length (a CRIME-class oracle when attacker-influenced data shares a
payload with secrets), so configuring `compression` together with
`encrypted: true` fails at boot unless
`compression_length_oracle_acknowledged: true` records an explicit, informed
acceptance. Note this addresses only the at-rest oracle; wire-level compression
(the HTTP `CompressionMiddleware`) is a separate BREACH surface with its own
controls.

### Distributed locking

Lock implementations parallel the driver layer: `ApcuLock`, `MemcachedLock`, `RedisLock`, `FilesystemLock`, `DatabaseLock`, `ArrayLock`. All implement `LockInterface` with acquire/release semantics and configurable TTL. `FencedExecutor` provides fenced token support for safe lock extension in long-running operations.

### Observability

`CacheEventEmitter` bridges cache operations to `MetricRegistry` and `LoggerInterface`:

- Counters: `cache_hits_total`, `cache_misses_total`, `cache_writes_total`, `cache_deletes_total`, `cache_errors_total`
- Labels: pool name, driver name, cache key
- Events: `CacheHitEvent`, `CacheMissEvent`, `CacheWriteEvent`, `CacheDeleteEvent`, `CacheClearEvent`, `CacheErrorEvent`

### Key validation

`CacheKeyValidator` enforces PSR-6 reserved character rules plus configurable maximum key length and allowed character sets. Validation runs on every public API entry point.

### Configuration

`CacheConfig` follows the readonly DTO pattern (ADR-0011) with `fromArray()` factory. Configures default driver, TTL, pool definitions, encryption settings, and stampede guard parameters.

## Alternatives considered

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

## Security impact

- `EncryptedCacheDecorator` uses framework crypto (libsodium, ADR-0006) - no custom cryptographic implementations
- AAD binding prevents ciphertext relocation between pools, keys, tenants, or purposes
- Key rotation is transparent - old ciphertexts are re-encrypted on read without application intervention
- `CacheKeyValidator` prevents injection via cache keys (reserved characters, length limits)
- Critical mode ensures security-sensitive cache failures (e.g., encrypted session store) surface as exceptions rather than silent degradation

## Performance impact

- Driver operations are I/O-bound. The application layer adds serialization (PHP serialize or JSON), key validation, and event emission - all sub-microsecond on modern hardware.
- Tag validation adds one `getTagVersions()` call per tagged read. `BestEffortTagStrategy` caches versions locally to amortize this cost.
- Stampede guard lock acquisition adds one round-trip to the lock backend on cache miss. The jittered TTL prevents synchronized expiration across distributed instances.
- Encryption adds ~50μs per operation (libsodium secretbox). Acceptable for most use cases; benchmark-sensitive paths should use unencrypted pools.

## Migration / rollback plan

Additive change - introduces `src/Cache/Application/` as a new core module. To roll back: remove the module and revert to direct PSR-6/PSR-16 library usage. Cache data is ephemeral by nature - no data migration required.

## Links

- ADR-0006: Libsodium-only crypto and master key derivation
- ADR-0007: In-house observability stack
- ADR-0011: Typed readonly configuration DTOs
- ADR-0016: Container dependency injection (driver/lock wiring)
