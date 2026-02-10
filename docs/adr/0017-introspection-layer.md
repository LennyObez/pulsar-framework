# ADR-0017: Introspection Layer

## Status

Accepted

## Context

Pulsar needs a structured way to expose project metadata — registered routes, container bindings, extension state, config schemas, console commands, and the public API snapshot — to developer tooling. Three consumers need this data:

1. **CLI** (`metadata:export`) for human/script consumption
2. **Studio** dashboard for visual introspection
3. **MCP Server** extension for AI-assisted development

Without a unified introspection layer, each consumer would independently crawl framework internals, creating coupling to `#[Internal]` types and duplicating sanitization logic. Extensions that contribute metadata (e.g., a payments extension exposing its webhook routes) have no safe channel to do so.

The introspection layer must balance openness (useful metadata) with security (no secrets, no absolute paths, bounded resource usage from untrusted contributors).

## Decision Drivers

1. **Single source of truth**: All consumers read the same `ProjectMetadataSnapshot` DTO — no divergent crawling logic.
2. **Security boundary**: Only `Introspection\Internal\` touches `#[Internal]` core types. The public surface is stable DTOs and a service facade.
3. **Contributor safety**: Extensions contribute metadata through `ProjectMetadataBuilder` with hard resource limits — preventing memory bombs, deep recursion, and key injection from untrusted payloads.
4. **No secrets in output**: Three-layer sanitization (contribution time, generation time, export time) ensures sensitive data never reaches consumers.

## Decision

Introduce `src/Introspection/` as a new core module with the following architecture:

### Public API (`#[Api]`)

- `ProjectMetadataSnapshot` — aggregate readonly DTO carrying all metadata sections plus warnings
- `ProjectMetadataService` — orchestrator that assembles the snapshot (memoized per-process)
- `MetadataContributorInterface` — extension hook for contributing custom metadata
- `ProjectMetadataBuilder` — mutable builder with per-contributor resource limits
- `IntrospectionConfig` — configuration DTO (enabled by default in local/staging, disabled in production)
- `Data/` — readonly DTOs for each metadata section (routes, commands, bindings, extensions, config schemas, API snapshot, contributed metadata)

### Internal (`#[Internal]`)

- `CoreRuntimeProbe` — crawls Container, ExtensionRegistry, Router, Console Application for runtime metadata
- `ConfigSchemaReflector` — reflects on config DTO classes to extract property schemas (no instantiation, no env var reading)
- `SnapshotFileReader` — reads `tools/api/public-api.snapshot.json` from disk

### Resource Limits

| Limit                              | Value                   | Rationale                         |
| ---------------------------------- | ----------------------- | --------------------------------- |
| Max sections per contributor       | 64                      | Prevent memory bombs              |
| Max nesting depth                  | 8                       | Prevent pathological recursion    |
| Max keys per object                | 200                     | Prevent wide-object DoS           |
| Max bytes per contributor          | 256 KB                  | Prevent memory exhaustion         |
| Max total bytes (all contributors) | 4 MB                    | Global memory cap                 |
| Allowed types                      | Scalars + arrays only   | Reject objects/resources/closures |
| Key format                         | `[a-z0-9_.-]`           | Prevent injection via keys        |
| Max warnings                       | 100, each max 512 chars | Prevent warning spam              |

### Sanitization (defense in depth)

1. **At contribution**: Builder enforces resource limits, normalizes keys, rejects non-serializable types
2. **At generation**: `build()` scrubs with `SensitiveDataScrubber`; `ConfigSchemaReflector` scrubs sensitive property defaults
3. **At export**: CLI and MCP apply final `SensitiveDataScrubber::scrub()` pass

### Path Handling

All paths in snapshot output are project-root-relative. Route handlers formatted as `Namespace\Class::method`. Config schema uses DTO class names. Bindings filtered to FQCN-like keys only (regex: `~^\\?[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)*$~`).

### Wiring

`IntrospectionWiring` registers the module in the Kernel boot pipeline. The service is available to extensions during the boot phase.

## Alternatives Considered

### Expose Container/Router/Registry directly to consumers

Rejected: creates coupling to `#[Internal]` types, no sanitization boundary, each consumer must independently filter secrets and normalize paths.

### Static file-only metadata (no runtime)

Rejected: misses dynamic state (registered bindings, extension lifecycle, runtime routes). The API snapshot file covers compile-time API surface but not runtime configuration.

### Unstructured contributor interface (extensions return raw arrays)

Rejected: no resource limits, no type safety, no sanitization guarantee. The builder pattern with validation ensures every contributor operates within safe bounds.

## Consequences

### Positive

- Unified metadata access for CLI, Studio, and MCP — no duplicated crawling logic
- Extension-contributed metadata is resource-bounded and sanitized
- Public API surface is stable DTOs — internal probe logic can evolve without breaking consumers
- Three-layer sanitization prevents secret leakage at every boundary

### Negative

- New core module adds ~15 files to `src/Introspection/`
- `CoreRuntimeProbe` depends on `#[Internal]` types (Container, ExtensionRegistry, Router, Application) — this coupling is intentional and contained

### Neutral

- Snapshot is memoized per-process — stale if services change after first call (acceptable for diagnostic tooling)
- Contributors run synchronously during snapshot generation — acceptable for the expected contributor count

## Security Impact

The introspection layer is a potential information disclosure vector. Mitigations:

- Production default: disabled unless `INTROSPECTION_ENABLED=true`
- No config values or env var values in output — only schema (property names, types, defaults with sensitive ones scrubbed)
- No absolute file paths — all paths project-root-relative
- Binding keys filtered to FQCN patterns — service-locator keys like `db.password` excluded
- Contributor payloads scrubbed through `SensitiveDataScrubber`

## Performance Impact

Snapshot generation involves reflection (config DTOs), registry iteration (extensions, routes, commands), and file I/O (API snapshot JSON). All operations are O(n) in the number of registered services/routes/extensions. Memoization ensures at most one generation per process. Not on the hot path — only invoked by CLI commands, Studio dashboard, or MCP tool calls.

## Migration / Rollback Plan

Additive change — no existing APIs modified. To roll back: remove `src/Introspection/`, `IntrospectionWiring`, and `config/introspection.php`. No data migrations required.

## Links

- ADR-0002: Module boundaries and `#[Internal]` namespace convention
- ADR-0009: `#[Api]` attribute-based public API surface
- ADR-0014: Kernel service wiring decomposition
