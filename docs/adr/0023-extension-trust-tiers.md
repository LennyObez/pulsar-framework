# ADR-0023: Extension Trust Tiers & Capability Enforcement

## Status

Accepted

## Context

Pulsar targets regulated domains - banking, healthcare, legal - where the supply-chain security of extensions is a first-class concern. The current extension system (ADR-0004) treats all extensions equally: once loaded via `pulsar.json`, every extension receives full `ContainerInterface` and `RouterInterface` access. This is a deliberate design choice for simplicity and third-party parity, but it creates unacceptable risk profiles:

- A compromised community extension can resolve `MasterKey`, raw database connections, and `AuditSinkInterface` from the container.
- Any extension can register routes at arbitrary paths, potentially shadowing `/login`, `/admin`, or `/_studio`.
- Middleware registration is unrestricted - a malicious extension could strip security headers or intercept authentication tokens.
- Extensions can bind services into the container, potentially overwriting critical framework bindings.
- Network egress, process execution, and environment variable access are unrestricted.

Compliance frameworks (SOX, HIPAA, PCI-DSS) require demonstrable controls over third-party code access to sensitive operations. An all-or-nothing model cannot satisfy these requirements.

## Decision drivers

1. **Compliance.** Regulated domains require auditable access controls over third-party code interacting with secrets, PII, and financial data.
2. **Defense-in-depth.** A single compromised extension should not cascade into full system compromise.
3. **Backward compatibility.** Existing first-party extensions and the bootstrap process must continue to work without modification when no policy is configured.
4. **Auditability.** Capability grants and denials must be deterministic, loggable, and explainable.
5. **Developer experience.** Error messages must tell extension authors exactly what capability is needed and how to request it.

## Decision

Introduce a four-tier trust model with capability-gated proxies for container and router access.

### Trust tiers

| Tier        | Intent                               | Resolution             |
| ----------- | ------------------------------------ | ---------------------- |
| `Core`      | First-party framework extensions     | Host policy allow-list |
| `Verified`  | Audited third-party extensions       | Host policy allow-list |
| `Community` | Unaudited third-party extensions     | Default for unknown    |
| `Untrusted` | Experimental or sandboxed extensions | Host policy allow-list |

### Requested vs. effective tier

Extensions declare a `trust_tier` in `pulsar.json` - this is **metadata only**, not a security boundary. The **effective tier** is resolved by the host application via `TrustedExtensionsConfig`:

```
pulsar.json: trust_tier = "verified"
Host config:  allowed_tier = "community"
───────────────────────────────────────────
Effective tier = min(requested, allowed) = "community"
```

If an extension is not in the host's allow-list, its effective tier defaults to `Community`. This ensures the host always controls the trust boundary.

### Capability model

Each tier grants a deterministic set of capabilities:

| Capability            | Core | Verified | Community | Untrusted |
| --------------------- | ---- | -------- | --------- | --------- |
| `ContainerRead`       | yes  | yes      | yes       | yes       |
| `ContainerWrite`      | yes  | yes      | yes       | no        |
| `RouteRegister`       | yes  | yes      | yes       | no        |
| `RouteRegisterGlobal` | yes  | yes      | no        | no        |
| `MiddlewareRegister`  | yes  | yes      | no        | no        |
| `CryptoKeyAccess`     | yes  | no       | no        | no        |
| `CryptoOperations`    | yes  | yes      | yes       | no        |
| `AuditWrite`          | yes  | yes      | yes       | no        |
| `AuditSinkAccess`     | yes  | yes      | no        | no        |
| `DatabaseRaw`         | yes  | yes      | no        | no        |
| `FilesystemWrite`     | yes  | yes      | no        | no        |
| `CommandRegister`     | yes  | yes      | yes       | no        |
| `NetworkEgress`       | yes  | yes      | no        | no        |
| `EnvRead`             | yes  | yes      | no        | no        |
| `ConfigWrite`         | yes  | yes      | no        | no        |
| `ProcessExec`         | yes  | no       | no        | no        |

The host can grant additional per-extension capabilities via `TrustedExtensionsConfig` (e.g., grant a specific community extension `DatabaseRaw`).

### Enforcement points

**Container access** - `ScopedContainerProxy` wraps `ContainerInterface`:

- `has()` delegates truthfully to the real container (PSR-11 compliance - never lies).
- `get()` checks the service against `ServiceRestrictionMap` and the extension's effective capabilities. Throws `CapabilityDeniedException` on denial.
- `bind()`/`instance()` require `ContainerWrite` capability.
- Core tier skips the proxy entirely (zero overhead).

**Service restriction** - `ServiceRestrictionMap` enforces deny-by-default:

- `restrictedServices`: maps service IDs to required capabilities (e.g., `MasterKey::class` requires `CryptoKeyAccess`).
- `safeServices`: explicit allowlist of services any tier with `ContainerRead` can resolve (loggers, config DTOs, event dispatcher).
- Unknown services (not in either list) are **denied** for non-Core tiers.

**Router access** - `ScopedRouterProxy` wraps `RouterInterface`:

- Community/Untrusted: routes must be registered under `/ext/{extension-name}/...` prefix (injected by proxy).
- Verified: no prefix constraint, but wildcard routes (`/{any}`) are rejected.
- Core: full access, no proxy.
- Reserved paths (`/login`, `/admin`, `/_studio`, `/api`) cannot be shadowed by non-Core extensions.

**Bootstrap integration** - `ExtensionBootstrap` resolves effective tiers and wraps container/router per extension during `register()` and `boot()` phases. When no policy is configured (null), the container and router are passed unwrapped for full backward compatibility.

### Error model

`CapabilityDeniedException` provides deterministic, actionable error messages:

```
Extension "acme/analytics" (Community tier) cannot resolve service
"Pulsar\Security\Crypto\MasterKey" - requires CryptoKeyAccess capability.

To grant this capability, add to config/extensions.php:
  'acme/analytics' => ['tier' => 'verified']
or grant the specific capability:
  'acme/analytics' => ['additional_capabilities' => ['CryptoKeyAccess']]
```

## Alternatives considered

### Runtime sandboxing (process isolation)

PHP does not support lightweight process isolation. `pcntl_fork()` is unavailable on Windows and introduces IPC complexity. Fibers provide concurrency but not isolation. External sandboxing (Docker, chroot) is too heavyweight for per-extension isolation and breaks the single-process deployment model.

Rejected: impractical in PHP without unacceptable performance and complexity costs.

### Static analysis only

A Deptrac-style ruleset could flag imports of sensitive classes at analysis time. However, static analysis cannot prevent runtime service resolution via the container (`$container->get('some-service')`), which is the primary attack vector.

Rejected: insufficient for runtime secrets and dynamic service resolution.

### Per-service ACL without tiers

Grant/deny individual service access per extension without tier abstraction. This provides maximum granularity but creates an O(n×m) configuration matrix (extensions × services) that is impractical to manage and audit.

Rejected: poor DX, audit nightmare, does not scale.

## Consequences

### Positive

- **Extension authors must declare required capabilities.** The manifest and host policy create an auditable access control record.
- **Defense-in-depth.** A compromised community extension cannot access secrets, raw database connections, or audit sinks.
- **Route integrity.** Community extensions cannot shadow framework routes or register wildcard catch-alls.
- **Deny-by-default.** New sensitive services are automatically restricted until explicitly classified.
- **Backward compatible.** No policy configured = no proxy = existing behavior unchanged.
- **Deterministic.** Same extension + same policy = same effective capabilities. No runtime negotiation.

### Negative

- **Community extensions need explicit capability grants.** Non-trivial community extensions will need the host to grant specific capabilities in `config/extensions.php`.
- **New services must be classified.** Any new service added to the container should be placed in the restriction map or safe allowlist.
- **Increased conceptual surface.** Extension authors must understand the tier/capability model to debug access denials.

### Neutral

- **First-party extensions unaffected.** All first-party extensions are tagged as `Core` in their manifests and the default host config.
- **Performance overhead is negligible.** Map lookup + capability check is O(1). Core tier bypasses the proxy entirely.

## Field report

_Placeholder - to be filled after operational experience._

## Security impact

Significant reduction in attack surface for third-party extensions:

- **Secrets exfiltration:** `MasterKey`, `KeyProviderInterface` restricted to Core tier only.
- **Database access:** Raw SQL connections restricted to Core + Verified tiers.
- **Audit tampering:** `AuditSinkInterface` restricted to Core + Verified; Community can write audit entries but not access the sink directly.
- **Route takeover:** Community extensions cannot register routes at `/login`, `/admin`, or other framework paths.
- **SSRF/exfiltration:** `NetworkEgress` capability restricts HTTP client access for Community/Untrusted.
- **Environment leakage:** `EnvRead` prevents community extensions from reading environment variables (which may contain secrets).

## Performance impact

Minimal. For Core-tier extensions: zero overhead (proxy bypassed). For other tiers: one `isset()` lookup in the restriction map + one `in_array()` check in the capability policy per `get()` call. Both are O(1) operations. The overhead is negligible compared to the service resolution and autoloading costs.

No impact on hot-path performance - capability checks happen during bootstrap (`register`/`boot` phases), not during request handling.

## Migration / rollback plan

**Adoption (gradual):**

1. All first-party extensions tagged `"trust_tier": "core"` in `pulsar.json`.
2. Host creates `config/extensions.php` with trusted extensions allow-list.
3. When `CapabilityPolicy` is set on `ExtensionBootstrap`, enforcement activates.
4. Without a policy (default), extensions behave exactly as before - full access.

**Rollback:**

1. Remove the `setCapabilityPolicy()` / `setTrustedExtensionsConfig()` calls from the application bootstrap.
2. Extensions revert to full container/router access.
3. No data migration, schema changes, or manifest updates required (the `trust_tier` field is ignored when no policy is active).

## Links

- ADR-0004: Extension-First Architecture with Manifest-Driven Lifecycle
- ADR-0009: Attribute-Based Public API Surface
- ADR-0013: Boundary Enforcement via API Interfaces
- OWASP Supply Chain Security
- MITRE ATT&CK: Supply Chain Compromise (T1195)
