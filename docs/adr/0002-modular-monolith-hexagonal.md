# ADR-0002: Modular monolith with hexagonal ports-and-adapters across crate boundaries

* **Status:** Accepted
* **Date:** 2026-04-26
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.22 (Architecture pattern), Decision 2.32 (Extended crate catalogue — fifty-three crates)
* **Sprint:** Sprint 0.5 (initial ADR batch)
* **Supersedes:** none

## Context

Pulsar Framework spans seven layers (kernel, security controls, core foundation, application infrastructure, data protection + authz + identity, API paradigms + AI + reactive + dev experience + orchestration + infrastructure adapters, application extensions; per plan Section III) plus a polyglot `services/` tree (admin SPA + Kubernetes Operator). The PHP baseline used a modular-monolith pattern with internal module boundaries; the Rust rewrite must decide whether to preserve the monolith, fragment into microservices, or adopt a different architectural pattern.

Three forces shape the decision:

1. **Operational footprint.** The four target verticals (banking, healthcare, legal, government) deploy in regulated environments where every additional process is an additional attack surface, an additional log stream, an additional configuration drift opportunity. A microservice-per-crate deployment multiplies the operational cost.
2. **Extension boundary.** Pulsar must support third-party extensions safely (per Decision 2.19 WASM sandbox). The extension boundary has different threat-model characteristics than the inter-crate boundary; mixing them collapses the trust model.
3. **Compile-time invariant enforcement.** Hexagonal architecture (ports + adapters) lets the inner layer define port traits and the outer layer provide adapter implementations. Adapter swapping (in-memory for tests, PostgreSQL for production, CockroachDB for multi-region) becomes a compile-time selection rather than runtime dispatch — preserving Rust's zero-cost abstraction guarantee.

## Decision

Pulsar Framework adopts a **composite architectural pattern**:

1. **Modular monolith** as the deployable shape. A single binary integrates every layer; deployments scale by replicating instances rather than by deploying each module separately.
2. **Hexagonal ports-and-adapters** at every crate boundary. The inner crate defines port traits; the outer crate (or a sibling crate) provides adapter implementations.
3. **Formally verified microkernel** for the six kernel subsystems (crypto primitives, audit HMAC chain, session state machine, router trie, middleware pipeline, DI container) — see ADR-0003.
4. **WASM-sandboxed extensions** with capability-based security at the periphery — see ADR-0004.
5. **Event-driven inter-module communication** via a typed internal event bus, first-class at the kernel level. Events are Rust types with `Debug` + `Clone` + `Serialize` bounds; subscribers register typed handlers at startup.

The dependency rule is strict: dependencies flow inward, never reverse. Application Extensions depend on Application Infrastructure; Application Infrastructure depends on Core Foundation; Core Foundation depends on Security Controls (`pulsar-guard`); Security Controls depend on the verified Kernel. Adapters at every boundary invert dependencies through ports defined in the inner layer. The rule is enforced at the workspace level via `cargo deny` rulesets and at the review level via mandatory manual check on every PR that modifies a `Cargo.toml`.

The hexagonal port discipline is the architectural mechanism that makes the twenty-three-framework compliance matrix tractable — jurisdictions demanding specific adapters (e.g. HSM-backed key storage, region-restricted S3 buckets) plug in without modifying the core.

## Consequences

### Positive

* Single deployable artefact reduces operational footprint (one process, one config, one log stream).
* Hexagonal port discipline makes adapter swapping zero-cost (compile-time selection); enables in-memory test adapters that mirror production behaviour without IPC overhead.
* Strict dependency rule enforced via `cargo deny` keeps the layered architecture honest as the codebase grows.
* Event bus decouples module lifecycles at compile time; inter-module messaging is typed and inspectable.
* WASM extension boundary cleanly separated from inter-crate boundary; trust models do not leak.
* Compliance matrix (`docs/compliance/matrix.md`) plugs in jurisdiction-specific adapters without core modification.

### Negative

* Single deployable means horizontal scaling is per-instance only; CPU- or memory-heavy subsystems cannot be scaled independently. Mitigated by Decision 2.27 resource budgets keeping per-instance footprint low.
* Hexagonal discipline imposes a learning curve on contributors used to flat module hierarchies.
* `cargo deny` ruleset must stay current with workspace evolution — every new crate addition is a small ADR-or-review event.

### Neutral

* Compile times grow with the workspace surface; mitigated by `mold` linker (~5x speedup per plan Section VII), `sccache` wrapper, and per-crate incremental compilation.
* Crate graph visualisation becomes more important as the workspace approaches fifty-three crates; addressed by the Mermaid diagram in plan Section IV and the ADR-0007 branch model that keeps the integration boundary clean.

## Alternatives considered

* **Pure microservices (one process per crate).**
  Rejected: operational cost incompatible with target deployment size; IPC overhead defeats Decision 2.27 resource budgets; observability fragments across processes.
* **Pure monolith without modular discipline.**
  Rejected: loses the extension story (Decision 2.19) and forfeits compile-time enforcement of layering.
* **Layered architecture without hexagonal discipline.**
  Rejected: layering alone does not invert dependencies; without ports + adapters the inner layer would import concrete backend types, blocking jurisdiction-specific adapter swapping.
* **Onion architecture (Robert Martin).**
  Rejected: variant of hexagonal with additional ceremony around domain isolation; the hexagonal pattern as Pulsar applies it already provides the invariants Onion would.
* **Domain-Driven Design with bounded contexts as crates.**
  Considered but folded into the layering: each crate is a bounded context, and the Section III layering enforces context boundaries.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.22, Section III Architecture Overview, Section IV Workspace Layout (53 crates).
* Risk register entries: R-008 (scope creep across phases), R-013 (parity scope slippage), R-019 (cross-crate API consistency).
* Related ADRs: ADR-0001 (full rewrite in Rust), ADR-0003 (microkernel with formal verification), ADR-0004 (WASM extension sandbox).
* External:
  * Cockburn, A. "Hexagonal Architecture." alistair.cockburn.us, 2005.
  * Martin, R. "The Clean Architecture." 2012.
  * Evans, E. "Domain-Driven Design: Tackling Complexity in the Heart of Software." Addison-Wesley, 2003.
