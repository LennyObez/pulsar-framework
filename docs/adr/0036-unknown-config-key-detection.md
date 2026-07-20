# ADR-0036: Unknown configuration key detection

## Status

Accepted

## Context

Pulsar's configuration is loaded from `config/*.php` files into typed DTOs via
each DTO's `fromArray()`. A key the DTO does not read is silently dropped to a
default. For a framework aimed at regulated, mission-critical domains this is a
real hazard: `security.session.handler` misspelled as `driver`, or
`cookie_secure` as `secur`, leaves the intended value unset with no error — the
operator believes a control is on while it is off.

The good behaviour already existed, but only for the cache: `CacheConfig`
enumerated its known keys, collected the unrecognized ones, and `CacheWiring`
logged them at boot. Nothing generalised this. A concrete miss surfaced in
review: `config/security.php` carried `session.driver` where `SessionConfig`
reads `handler`, and it was ignored with zero warning.

## Decision drivers

- Silent misconfiguration is a security and compliance risk, not a mere DX papercut.
- The reference implementation (cache) should be generalised, not duplicated.
- A brand-new, hand-enumerated key list must not break existing deployments on upgrade.
- Detection must reach nested config (a typo in `security.session.*`, not just top level).

## Decision

Introduce a single, reused mechanism and one chokepoint:

- **`UnknownKeys::collect($raw, $known)`** — the shared primitive returning the
  keys present but unrecognized.
- **`ReportsUnknownKeys`** — a config DTO exposes `unknownConfigKeys()`, the
  keys (section-relative, dotted for nesting) it did not recognize. A parent
  DTO rolls up each child that implements the interface, prefixing with the
  child's path, so `security.session.driver` is reported even though the typo
  lives two levels down.
- **`ConfigManager::auditUnknownConfigKeys()`** — after every section (framework
  and extension) is loaded, sweep `ConfigRepository::all()` for
  `ReportsUnknownKeys` implementors. This is the single detection point.

**`KNOWN_KEYS` means every key the section legitimately holds across all
consumers, not merely those the typed DTO reads.** `config/security.php`, for
instance, carries `threat_detection`, `waf`, `tokenization` and `key_overrides`
that their own wirings read raw; they belong in `SecurityConfig::KNOWN_KEYS`
even though `SecurityConfig::fromArray()` ignores them. A drift test loads the
shipped `config/*.php` and asserts zero unknown keys, so an enumeration that
falls out of sync fails CI.

### Strictness — warn by default, opt in to fail-closed

Precedence: `PULSAR_CONFIG_STRICT` env, then `config.strict_keys` in
`config/app.php`, then the default — **warn**.

Default warn is deliberate. Shipping strict on a freshly hand-enumerated key
list would turn any missed-but-valid key, or a legacy extra key in an existing
deployment, into a production boot failure on upgrade. (The drift test proved
this risk immediately: it caught four un-enumerated security keys that would
have broken a clean install under strict-by-default.) Operators opt into
fail-closed once they trust their config; the default can flip to
strict-in-production after the enumeration has proven itself across a release.

Warnings are surfaced at boot by `ConfigDiagnosticsReporter` through the real
logger; strict mode aggregates every unknown key into one `ConfigException` so
the operator sees them all at once rather than one failed boot at a time.

## Consequences

- Migrated so far: `CacheConfig` (adopted; its bespoke `CacheWiring` log
  removed to avoid double-reporting), `SessionConfig`, `SecurityConfig`.
- Remaining root config sections are migrated incrementally; because the
  default is warn and unmigrated sections simply are not swept, partial
  coverage is safe and additive.
- Adding a section to the mechanism is: implement `ReportsUnknownKeys`, declare
  `KNOWN_KEYS`, collect in `fromArray()`, and extend the drift test.

## Alternatives considered

- **Strict-by-default (throw on any unknown key).** Rejected for now: reckless
  with a fresh enumeration and a BC break on upgrade. Revisit post-GA.
- **Reflection over `fromArray` PHPDoc shapes to derive known keys.** Rejected:
  fragile and implicit, against the framework's explicitness preference, and it
  would miss keys consumed raw by other components.
- **Per-wiring logging (the cache status quo) for every section.** Rejected:
  duplicative and easy to forget; a single chokepoint is the point.
