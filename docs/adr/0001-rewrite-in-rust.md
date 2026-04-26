# ADR-0001: Full rewrite of Pulsar Framework from PHP 8.5 to Rust 1.95+

* **Status:** Accepted
* **Date:** 2026-04-26
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.1 (Full rewrite from PHP 8.5 to Rust 1.95+), Decision 2.2 (Target domains: regulated banking, healthcare, legal, government), Decision 2.3 (Rigour level: 100 % state-of-the-art absolute), Decision 2.7 (Rust 1.95.0 stable, edition 2024)
* **Sprint:** Sprint 0.5 (initial ADR batch)
* **Supersedes:** none

## Context

Pulsar Framework reached release candidate `1.0.0-rc.11` on PHP 8.5 with sixty `src/` modules, thirty-one first-party extensions, approximately five hundred thousand lines of code, an HMAC-chained audit log, attribute-driven public surface, eighteen-framework compliance matrix, OAuth 2 + WebAuthn auth suite, and a CMS with eighty-plus admin controllers.

Three properties of PHP block the next-stage assurance Pulsar targets:

1. **No memory-safety guarantee at the language level.** PHP's runtime checks catch typing violations only at the moment of access; spatial and temporal memory errors that would surface as defined behaviour in a Rust deny-warnings build remain hidden in PHP.
2. **No expressive type system to encode invariants.** PHP's type annotations are runtime-checked and erasable. Pulsar's audit chain, session state machine, router trie, and middleware pipeline carry invariants that a sound type system can encode at compile time (e.g. typed-state session transitions, sealed-trait middleware ordering, capability tokens). PHP cannot.
3. **Garbage-collected runtime with unpredictable tail latency.** Pulsar's target verticals (banking real-time payment rails, healthcare EHR APIs, legal courtroom-scale concurrent sessions) require deterministic tail latency. PHP's reference-counted GC with cycle collector is not predictable enough at the 99.99th percentile.

The four target verticals are intolerant of these gaps. Java enterprise frameworks address (1) and (2) but reintroduce (3) through JVM warm-up and GC pauses, plus a memory baseline incompatible with the per-instance resource envelopes typical of regulated deployments. Go addresses (3) but its type system is materially weaker than Rust's for encoding the invariants Pulsar needs.

## Decision

Pulsar Framework is rewritten from scratch in **Rust 1.95.0 stable edition 2024**, pinned via `rust-toolchain.toml`. The PHP tree is frozen at the terminal tag `v0.99.0-php-final`; the Rust rewrite is the sole forward-going implementation.

The rewrite preserves every strategic intent and every subsystem of the PHP lineage (per Decision 2.31 strict-parity commitment, audited module-by-module in plan Section XV PHP-to-Rust parity matrix) while elevating engineering rigour to the seL4 microkernel + CompCert + SQLite + LMAX Disruptor + Erlang OTP standard (Decision 2.3).

The decision is a **full rewrite, not a hybrid deployment, not a foreign-function-interface bridge, not a compatibility layer**. PHP-Rust hybrids would freeze PHP idioms (PSR-compliant interfaces, array-shaped configuration, attribute-driven introspection) into the Rust tree and prevent the re-encoding of invariants into the type system, which is the single largest gain the rewrite seeks.

## Consequences

### Positive

* Memory safety end-to-end via Rust ownership (Decision 2.3 rigour target).
* Type-system encoding of audit, session, router, middleware, and capability invariants (per Section III architecture).
* Deterministic latency without GC pauses (per Decision 2.27 resource budgets and Section XII success metrics: P99 < 1 ms, P99.99 < 5 ms, idle memory < 50 MB).
* Single-language stack across the framework (Rust) plus principled polyglot exceptions (Go for the Kubernetes Operator per Decision 2.46, native HTML5 + ES2025 + Web Components for the admin SPA per Decision 2.8).
* Compile-time invariant verification reduces the runtime test surface for critical paths.
* Formal verification (Creusot + TLA+ per Decision 2.20) becomes tractable on the kernel layer.
* Public crates.io distribution (Decision 2.23 `pulsar-*` namespace) widens the addressable contributor pool beyond the PHP community.

### Negative

* Zero code reuse from the PHP tree; complete reimplementation cost.
* Calendar-extended development period (multi-year solo project per Decision 2.48).
* Existing PHP downstream applications face a forced migration (R-005 risk register entry tracks this).
* Contributor-pool shift: existing PHP contributors must relearn Rust idioms; new Rust contributors may lack regulated-domain context.

### Neutral

* Tooling shift to Cargo + rustfmt + clippy + cargo-deny + cargo-audit (per plan Section VII).
* Build pipeline shift from PHP-specific tooling to Cargo workspace + GitHub Actions (per plan Section 8 git workflow + workflows landed in Sprint 0.4).
* Dependency model shifts from Composer to Cargo with stricter version pinning per `[workspace.dependencies]`.

## Alternatives considered

* **Gradual port with PHP calling Rust via FFI.**
  Rejected: leaks PHP lifecycle into Rust, doubles operational footprint, complicates the audit surface, defeats the type-system gain.
* **Transpilation (PHP → Rust via an automated converter).**
  Rejected: the PHP tree is not sufficiently uniform to transpile safely; resulting Rust would not compile on the same semantic footing as hand-written code.
* **Rust-side shim exposing the PHP interfaces.**
  Rejected: the same idiom-leakage problem at a lower abstraction level; the shim would freeze PHP-shaped interfaces in Rust.
* **Continue on PHP, refactor incrementally.**
  Rejected: caps the achievable assurance below the regulated-domain bar (Decision 2.3 rigour target). PHP cannot deliver formal verification at the kernel layer or deterministic tail latency.
* **Migrate to Go.**
  Rejected: deterministic latency improves over PHP but the type system is weaker than Rust's for encoding the invariants Pulsar needs (typed sessions, sealed middleware, capability tokens). Memory safety is via GC rather than ownership.
* **Migrate to Java + Quarkus / Spring native.**
  Rejected: GraalVM native-image build fragility, JVM warm-up + GC tail latency, much larger memory baseline incompatible with Decision 2.27 resource budgets.

## References

* Plan section(s): `docs/plan.md` Section I executive summary, Section II Decisions 2.1 + 2.2 + 2.3 + 2.7 + 2.31 + 2.48, Section XII success metrics, Section XV PHP-to-Rust parity matrix.
* Risk register entries: R-005 (PHP downstream migration friction), R-013 (parity scope slippage), R-017 (solo velocity at expanded scope).
* Related ADRs: ADR-0002 (modular monolith with hexagonal ports), ADR-0003 (microkernel with formal verification), ADR-0005 (Apache-2.0 licence).
* External:
  * Klein, Elphinstone, Heiser, et al. "seL4: formal verification of an operating-system kernel." CACM, 2010.
  * Leroy, X. "Formal verification of a realistic compiler." (CompCert), CACM 2009.
  * SQLite project. "How SQLite Is Tested." sqlite.org/testing.html.
  * Rust Project. "rust-toolchain.toml" reference. rust-lang.github.io/rustup/overrides.html.
