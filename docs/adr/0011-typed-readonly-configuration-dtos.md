# ADR-0011: Typed Readonly Configuration DTOs with Deterministic Load Order

## Status

Accepted

## Context

PHP frameworks typically represent configuration as nested associative arrays accessed via dot-notation helpers (e.g., `config('database.host')`). This approach has several problems:

- **No type safety.** Array access returns `mixed`. Typos in key names produce `null` instead of errors. Incorrect types are discovered at runtime, not at static analysis time.
- **No IDE support.** Dot-notation strings are opaque to IDEs — no autocompletion, no refactoring, no "find usages."
- **Unclear precedence.** When environment variables, `.env` files, PHP config files, and runtime overrides all contribute values, the merge order is often implicit and surprising.
- **Mutable at runtime.** Array-based config can be modified after boot, leading to inconsistent behavior between early and late consumers.

## Decision

All configuration in Pulsar is represented as `readonly` DTO classes with `fromArray()` factory methods, loaded in a deterministic, documented order.

### Load order (strict precedence, highest wins)

1. **OS environment variables** — always present, highest priority.
2. **`.env` file** — optional. Values never override existing OS vars.
3. **PHP config files** (`config/app.php`, `config/security.php`, etc.) — return raw arrays.
4. **Runtime overrides** — `ConfigOverrides` applied via `array_replace_recursive` on raw arrays.
5. **Typed DTO construction** — `AppConfig::fromArray()`, `SecurityConfig::fromArray()`, etc. Env vars are resolved inside factory methods, providing final override opportunity.

### DTO conventions

- All config DTOs are `readonly class` with public properties.
- Factory method: `public static function fromArray(array $data): static`.
- Defaults are specified in the factory method, not in property declarations.
- Nested config uses composed DTOs (e.g., `SecurityConfig` contains `SessionConfig`, `CsrfConfig`, `SecurityHeadersConfig`, `RateLimitConfig`).
- Environment variable overrides are documented per-property in each config file's docblock.

### Examples

- `AppConfig` — app name, mode, debug, timezone, locale, URL.
- `SecurityConfig` — session, CSRF, headers, rate limiting.
- `ObservabilityConfig` — logging channels, audit settings.

## Consequences

### Positive

- **Full type safety.** PHPStan and Psalm verify configuration access at analysis time. Typos and type mismatches are caught before runtime.
- **IDE autocompletion.** `$config->session->lifetime` is navigable, refactorable, and searchable — unlike `config('security.session.lifetime')`.
- **Immutable after construction.** `readonly` prevents runtime modification. All consumers see the same values.
- **Documented precedence.** The five-step load order is explicit and deterministic. No ambiguity about which source wins.

### Negative

- **More boilerplate.** Each config section requires a DTO class with a factory method, compared to a single array file. The cost is proportional to the number of config sections, not the number of settings.
- **No dynamic config.** Runtime config changes (e.g., feature flag toggling via admin panel) cannot modify readonly DTOs. Dynamic settings must use a separate mechanism (e.g., the feature flag system).
- **Migration effort.** Applications moving from array-based config must define DTO classes for their custom config sections.

### Neutral

- **Config stubs in `config/`.** The framework ships stub config files that return default arrays. Applications override only the values they need. The stubs serve as living documentation.
