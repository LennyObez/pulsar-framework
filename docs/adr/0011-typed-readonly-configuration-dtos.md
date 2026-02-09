# ADR-0011: Typed Readonly Configuration DTOs with Deterministic Load Order

## Status

Accepted

## Context

PHP frameworks typically represent configuration as nested associative arrays accessed via dot-notation helpers (e.g., `config('database.host')`). This approach has several problems:

- **No type safety.** Array access returns `mixed`. Typos in key names produce `null` instead of errors. Incorrect types are discovered at runtime, not at static analysis time.
- **No IDE support.** Dot-notation strings are opaque to IDEs - no autocompletion, no refactoring, no "find usages."
- **Unclear precedence.** When environment variables, `.env` files, PHP config files, and runtime overrides all contribute values, the merge order is often implicit and surprising.
- **Mutable at runtime.** Array-based config can be modified after boot, leading to inconsistent behavior between early and late consumers.

## Decision

All configuration in Pulsar is represented as `readonly` DTO classes with `fromArray()` factory methods, loaded in a deterministic, documented order.

### Load order (strict precedence)

Configuration is assembled in a single pass, not layered with multiple override opportunities:

1. **OS environment variables** - loaded into `Environment` (always present).
2. **`.env` file** - optional, merged into `Environment`. `.env` values never override existing OS vars.
3. **PHP config files** (`config/app.php`, `config/security.php`, etc.) - return raw arrays with default values.
4. **Runtime overrides** - `ConfigOverrides` applied via `array_replace_recursive` on the raw arrays.
5. **Typed DTO construction** - `AppConfig::fromArray()`, `SecurityConfig::fromArray()`, etc. Factory methods read env vars for documented override keys (e.g., `APP_NAME`, `APP_ENV`). Env vars win over array values - this is where the "env is highest priority" rule is enforced, not as a separate layer.

The key invariant: env vars are the canonical source of truth for any setting they override. Factory methods are pure mapping + validation with env var lookups for documented keys. There is no additional override layer after DTO construction.

### DTO conventions

- All config DTOs are `readonly class` with public properties.
- Factory method: `public static function fromArray(array $data): static`.
- Defaults are specified in the factory method, not in property declarations.
- Nested config uses composed DTOs (e.g., `SecurityConfig` contains `SessionConfig`, `CsrfConfig`, `SecurityHeadersConfig`, `RateLimitConfig`).
- Environment variable overrides are documented per-property in each config file's docblock.

### Examples

- `AppConfig` - app name, mode, debug, timezone, locale, URL.
- `SecurityConfig` - session, CSRF, headers, rate limiting.
- `ObservabilityConfig` - logging channels, audit settings.

## Consequences

### Positive

- **Full type safety.** PHPStan and Psalm verify configuration access at analysis time. Typos and type mismatches are caught before runtime.
- **IDE autocompletion.** `$config->session->lifetime` is navigable, refactorable, and searchable - unlike `config('security.session.lifetime')`.
- **Immutable after construction.** `readonly` prevents runtime modification. All consumers see the same values.
- **Documented precedence.** The five-step load order is explicit and deterministic. No ambiguity about which source wins.

### Negative

- **More boilerplate.** Each config section requires a DTO class with a factory method, compared to a single array file. The cost is proportional to the number of config sections, not the number of settings.
- **No dynamic config.** Runtime config changes (e.g., feature flag toggling via admin panel) cannot modify readonly DTOs. Dynamic settings must use a separate mechanism (e.g., the feature flag system).
- **Migration effort.** Applications moving from array-based config must define DTO classes for their custom config sections.

### Neutral

- **Config stubs in `config/`.** The framework ships stub config files that return default arrays. Applications override only the values they need. The stubs serve as living documentation.

## Field Report

_Optional. Document operational experience that validates or challenges this decision. Add entries as they accumulate._

- **rc.4 – rc.10** | Feature flag implementation: The readonly DTO approach confirmed its strength for static configuration - type safety and IDE support have eliminated an entire class of config-related bugs. However, dynamic configuration needs (feature flag toggling via admin panel, A/B test cohort assignment) cannot be served by readonly DTOs. This led to the creation of a separate `FeatureFlagConfig` system with mutable runtime state, backed by a persistent store. The boundary between static config (readonly DTOs) and dynamic config (feature flags) is now explicit and well-understood. Readonly DTOs remain the correct choice for settings that are fixed at boot time.
