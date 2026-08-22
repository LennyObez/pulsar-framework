# ADR-0033: Bind the loaded Environment as a process-global so `env()` resolves `.env`

## Status

Accepted

## Context

Pulsar loads environment variables through `Pulsar\Config\Environment`, which
merges the OS process environment with an optional `.env` file (OS wins) into an
in-memory map. Operational secrets are deliberately **not** exported to the OS
process environment via `putenv()` — doing so leaks them to child processes and
`/proc/self/environ`, which is precisely the exposure the loader's prefix
allowlist exists to prevent.

The global `env()` helper (`src/Support/functions.php`) has no access to that
in-memory map. `getenv()` never sees `.env`-loaded values, so:

- every `env('SECRET')` call in an application's `config/*.php` files (and in the
  framework's own config files, e.g. `config/security.php`'s
  `env('SESSION_COOKIE_SECURE', …)`) silently misses a value provided only in
  `.env`, and
- a production deployment that keeps secrets in `.env` (a common, supported layout)
  sees those values resolve to their defaults.

The typed config DTOs already read the `Environment` repository directly and are
unaffected; the gap is specific to the free function. It is also silent — no
error is raised, the default is simply returned.

A free function called at config-file `require` time has no dependency-injection
seam — it cannot be handed an `Environment` instance. The only way for `env()` to
read the loaded environment is to consult ambient state.

## Decision

`Environment` gains a process-global "active" instance:

- `Environment::activate(self $environment): void` — bind the active instance.
- `Environment::active(): ?self` — read it (null before bootstrap).
- `Environment::resetActive(): void` — clear it (used by tests for isolation).

`ConfigManager::load()` and `ConfigManager::loadFromCache()` call `activate()`
immediately after building their `Environment`, **before** any `config/*.php`
file is required — so config files resolve `.env` values through `env()`.

`env($key, $default)` resolves in this order:

1. `Environment::active()?->get($key)` — the merged OS + `.env` map, OS-wins,
   honouring whatever allowlist the active loader applied. When active is bound
   and the key is absent, return `$default` (the active map already incorporates
   the OS environment, so a `getenv()` re-check would be redundant).
2. `getenv($key)` — fallback for the pre-bootstrap window and for unit tests that
   do not load configuration.
3. `$default`.

The well-known string coercions (`true`/`false`/`null`/`empty`) are unchanged and
apply to both paths.

This is strictly **additive**: any key that resolved before (it was in the OS
environment) resolves identically; only `.env`-only keys that previously fell
through to the default now resolve.

## Decision drivers — why a process-global is acceptable here

CLAUDE.md mandates _"avoid global state."_ That rule targets **mutable
service/dependency global state** — service locators, swappable singletons,
ambient request context — because it hides dependencies and defeats testing. The
active `Environment` is a different category:

1. **It is bootstrap configuration, not a service.** It is set once at boot from
   immutable, already-merged data; it is never a behavioural dependency that code
   resolves to _do_ work.
2. **There is no alternative for a free function.** `env()` is, by definition, a
   global helper invoked where no container is in scope (config files, helpers).
   Every mature PHP framework (Laravel, Symfony) resolves `env()` against exactly
   such a bootstrap-populated repository.
3. **It does not leak secrets.** Unlike `putenv()`, the active map stays in
   process memory and never reaches the OS environment or child processes.
4. **Test isolation is enforced.** A PHPUnit extension
   (`ResetActiveEnvironmentExtension`) calls `resetActive()` before every test,
   so the global cannot leak across the suite.

## Consequences

- Config files and application code that read secrets via `env()` now honour
  `.env`, matching operator expectations and the documented behaviour of `.env`.
- `env()` remains a **bootstrap-time helper**: application runtime code should
  prefer typed config (`ConfigManager`/config DTOs) or
  `Environment::get()`/`require()`, both for testability and because a future
  cached-config mode may bypass `env()` entirely. This is documented on the
  helper.
- The active loader's filtering is inherited by `env()`. `ConfigManager` uses the
  unfiltered `Environment::load()` today, so `env()` exposes the same surface it
  did under `getenv()`. Switching the bootstrap to `loadFiltered()` would
  additionally constrain `env()` to the prefix allowlist — tracked separately.
- `Environment` becomes a stateful holder of one static reference; the surface is
  three small methods and is covered by the no-leak test isolation above.
