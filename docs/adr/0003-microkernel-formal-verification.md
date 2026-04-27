# ADR-0003: Formally verified microkernel for crypto, audit, session, router, middleware, DI

* **Status:** Accepted
* **Date:** 2026-04-26
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.20 (Formal verification: Creusot + TLA+), Decision 2.22 (Architecture pattern composite), Decision 2.3 (Rigour level: 100 % state-of-the-art absolute)
* **Sprint:** Sprint 0.5 (initial ADR batch)
* **Supersedes:** none

## Context

Pulsar's target verticals (banking, healthcare, legal, government) treat framework defects as compliance liabilities, not feature regressions. The cost of a defect in a kernel subsystem (cryptographic primitive misuse, audit-chain tampering, session state-machine bypass, route-resolution determinism, middleware ordering inversion, DI container cycle) is denominated in regulatory fines, licensure loss, or criminal exposure — not in user churn.

Conventional testing (unit + integration + property + fuzz) catches the failure modes that a finite test corpus can express. It cannot prove the absence of the failure modes that the corpus does not enumerate. For kernel-level invariants where the cost of an unenumerated failure is regulatory exposure, finite testing is insufficient on its own.

Two formal-verification toolchains are mature enough in the 2026 Rust ecosystem to pay back their cost on this surface:

1. **Creusot** — function-contract verification with SMT solver backends (Z3, CVC5). Expresses pre/post conditions and loop invariants directly in Rust syntax via `#[requires]` + `#[ensures]` + `#[invariant]` attributes. Verifies at compile time.
2. **TLA+** — protocol-level specification language with the TLC model checker. Expresses distributed and concurrent protocol properties at the abstraction level where liveness and safety claims are machine-checkable.

The two tools cover disjoint verification surfaces: Creusot proves function-level invariants for a single execution; TLA+ proves protocol-level invariants across concurrent and distributed executions.

## Decision

Pulsar Framework adopts a **formally verified microkernel** containing exactly six subsystems:

| Subsystem | Verified property | Tool |
|-----------|-------------------|------|
| Crypto primitives | Key lifecycle correctness; constant-time invariants delegated to `subtle` + `ring` | Creusot contracts on key derivation + AEAD + signature; TLA+ spec `spec/crypto.tla` for key lifecycle |
| Audit HMAC chain | Tamper-evident append-only invariant; "any single-byte tampering fails verification" | Creusot contracts on `append` + `verify`; TLA+ spec `spec/audit.tla` |
| Session state machine | Typed-state transitions; "no path from Anonymous to Elevated bypasses Authenticating" | Creusot contracts on transitions; TLA+ spec `spec/session.tla` |
| Router trie | Constant-time worst-case path resolution; "each path resolves to at most one route" | Creusot contracts on `resolve` + `register`; TLA+ spec `spec/router.tla` |
| Middleware pipeline | Total-order ordering; "no middleware runs twice per request" | Creusot contracts on composition; TLA+ spec `spec/middleware.tla` |
| DI container | Compile-time topological-sort cycle detection; type-checked resolution | Creusot contracts on `resolve` (no TLA+ — sequential, no protocol) |

Three additional TLA+ specs cover protocol-level invariants outside the microkernel: `spec/oauth2.tla` (Sprint 2.5 OAuth 2.1 + PKCE flow), `spec/saml.tla` (Sprint 2.5 SAML SSO with assertion replay defence), `spec/websocket.tla` (Sprint 3B.2 inbound dispatch lifecycle), `spec/orchestration.tla` (Sprint 3E.1 workflow + saga). Per plan Section XII success metrics, **nine TLA+ specifications** ship at GA.

Verification scope:

* **Kernel crates** (`pulsar-kernel`): TLA+ spec + Creusot function contracts where tractable. Target: ≥ 80 % Creusot contract coverage across kernel functions per Section XII.
* **Security controls** (`pulsar-guard`): Creusot contracts on verification functions (CSRF token gen/verify, SRI digest invariants, rate-limiter decision functions).
* **`pulsar-dataprotection` RtbF two-phase commit:** Creusot contracts plus property tests on commit + abort invariants.
* **All other crates:** property-based testing (`proptest`) as the formal-discipline floor; formal verification optional.

CI runs `cargo creusot` on every kernel sprint exit and `tlc -config <spec>.cfg <spec>.tla` on every TLA+ spec for every protocol-touching sprint.

## Consequences

### Positive

* Kernel invariants are machine-verified, not just tested. Defects in the verified surface are caught at proof time, not in production.
* The verified subset is the surface where defects would be most costly and most hidden — the disproportionate share of the assurance budget pays back.
* TLA+ specs serve as living documentation of protocol semantics — readable by reviewers without reading Rust.
* Creusot contracts double as inline documentation of function pre/post conditions, replacing prose comments with machine-checkable invariants.
* Regulated-domain procurement evaluates formal verification claims as a maturity signal; having artefacts to point to (TLA+ files, Creusot proof reports) materially shortens evaluation cycles.

### Negative

* Sprint velocity is materially reduced on kernel sprints. The Section II Decision 2.33 ship-when-correct cadence absorbs this, but the multi-year solo framing (Decision 2.48) explicitly acknowledges the trade-off.
* Creusot prover coverage in 2026 is realistically ~85-90 % of seL4-style 100 %. R-001 risk register tracks this; the gap is filled by `proptest` and integration tests.
* Formal-verification tooling (Z3, CVC5, TLC, Creusot) increases the CI image size and the contributor onboarding surface.
* TLA+ specs require a learning curve for contributors unfamiliar with temporal-logic specification languages.
* Refactoring the verified surface requires re-proving every affected contract — discourages opportunistic refactoring on kernel code.

### Neutral

* Verification tooling versions are pinned per plan Section 13.3 tool version matrix; quarterly review (per risk R-016) catches breaking changes.
* Creusot annotations are `#[cfg(not(feature = "verified"))]`-stripped in release builds; runtime cost is zero.

## Alternatives considered

* **Test-only assurance (no formal verification).**
  Rejected: the gap between "tested" and "verified" is the gap regulated procurement evaluates. Pulsar without formal verification is at the same maturity tier as `axum` or `actix-web` — not a differentiator.
* **Isabelle/HOL or Coq instead of Creusot.**
  Rejected: external proof environments require translating Rust semantics into the proof tool. Creusot keeps the proof in-source. Isabelle/HOL is the seL4 path; matching it would multiply the maintainer effort by an order of magnitude on a project that is solo-bootstrapped.
* **Prusti instead of Creusot.**
  Rejected: tooling maturity in 2026 is lower than Creusot. Prusti's verification is sound on a smaller subset; Creusot's larger subset matters at the kernel scale.
* **Kani (bounded model checker) instead of Creusot.**
  Rejected: bounded model checking proves properties up to a depth bound, not for all admissible inputs. Useful for fuzzing-equivalent symbolic exploration (and folded into Sprint 4.4 advanced fuzzing per plan Section 14.16) but not a replacement for Creusot's inductive proofs.
* **Alloy instead of TLA+.**
  Rejected: weaker temporal logic than TLA+; less industry tooling around model checking at the protocol scale Pulsar targets.
* **Verify the entire framework, not just the kernel.**
  Rejected: prover effort scales with code surface; verifying every crate would multiply effort by 10-50x on a solo project. The microkernel-only scope concentrates effort where defects are most costly.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.20 + 2.22 + 2.3, Section III Architecture Overview (microkernel composition), Section XII success metrics (9 TLA+ specs), Section 17.27 formal verification scope.
* Risk register entries: R-001 (Creusot prover coverage gap).
* Related ADRs: ADR-0001 (full rewrite in Rust), ADR-0002 (modular monolith with hexagonal ports), ADR-0004 (WASM extension sandbox).
* External:
  * Klein, G., Elphinstone, K., Heiser, G., et al. "seL4: formal verification of an operating-system kernel." CACM, 2010.
  * Leroy, X. "Formal verification of a realistic compiler." (CompCert), CACM 2009.
  * Lamport, L. "Specifying Systems: The TLA+ Language and Tools for Hardware and Software Engineers." Addison-Wesley, 2002.
  * Creusot project. "Creusot: a verifier for Rust programs." creusot-rs.github.io.
  * Z3 SMT solver. github.com/Z3Prover/z3.
  * CVC5 SMT solver. cvc5.github.io.

## Compliance mapping

Formal verification at the kernel layer is the technical artefact that satisfies the highest-rigour evidentiary requirements in regulated procurement. Every TLA+ spec + Creusot contract is a machine-checkable demonstration that an invariant holds for all admissible inputs — a strictly stronger claim than test-based coverage.

* **Common Criteria ISO/IEC 15408 — EAL 6+ / EAL 7** (semi-formally / formally verified design and tested) — `pulsar-kernel` is the locus that, once formally verified, supports ATE_DPT.4 (testing: depth — implementation representation) + ADV_FSP.6 (functional specification with complete formal presentation) + ADV_TDS.6 (TOE design with complete formal presentation). Pulsar does not pursue a full CC certification; the artefacts position downstream integrators to do so.
* **NIST FIPS 140-3** (cryptographic-module security) — Level 4 demands "formal model is shown to be consistent with policy". Crypto primitives in `pulsar-kernel` carry Creusot contracts on key-lifecycle invariants + TLA+ spec `spec/crypto.tla`; `subtle`+`ring` deliver constant-time guarantees underneath. FIPS 140-3 validation pathway begins Sprint 4.7.
* **ISO/IEC 27001:2022 A.8.27** (secure system architecture and engineering principles) — TLA+ specs documenting kernel-subsystem invariants ARE the secure-architecture documentation A.8.27 demands.
* **ISO/IEC 27034-3:2018** (application security — application security management process) — § 7 application security verification is satisfied by automated formal-verification gates in CI.
* **PCI-DSS 4.0 Req. 6.2.3.1** (custom and bespoke software is reviewed and approved before being released) — Creusot contracts + TLA+ model-checker results are the machine-readable evidence of pre-release verification.
* **PSD2 RTS Art. 4** (security requirements for payment service providers — strong customer authentication) — `spec/oauth2.tla` covers the SCA flow's session-state-machine invariants. Authentication state transitions are proven safe at the protocol level.
* **DORA Art. 9(2)** (ICT-related incident management — root-cause analysis) — formal-verification artefacts let post-incident root-cause analysis discriminate "verified subsystem (root cause is upstream/configuration)" from "non-verified subsystem (root cause is implementation defect)".
* **DORA Art. 26-27** (digital operational resilience testing — advanced testing with TLPT) — formal-verification reports complement Threat-Led Penetration Testing by ruling out entire defect classes from the test scope.
* **EU AI Act Art. 15(1)** (high-risk AI systems — accuracy, robustness, cybersecurity) — `pulsar-ai-governance` policy decisions cross the verified kernel; the kernel's verification supports Art. 15(1) cybersecurity claims by construction.
* **NIST SP 800-160 Vol. 1 Rev. 1** (engineering trustworthy secure systems) — § 3.4.4 "Verification" clause is satisfied at the highest tier (formal proof) for the kernel surface.
