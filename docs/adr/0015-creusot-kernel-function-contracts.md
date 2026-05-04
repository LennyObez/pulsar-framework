# ADR-0015: Creusot for kernel function contracts

* **Status:** Accepted
* **Date:** 2026-04-29
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.20 (Formal verification: Creusot + TLA+ + HACL\* + SPARK 2014), Decision 2.59 (15 TLA+ specifications at GA — Creusot complements protocol-level specs with function-level contracts), Decision 2.53 (HACL\* via FFI for classical primitives — Creusot contracts annotate the Rust-side wrapper surface, not the HACL\* C primitives themselves), Decision 2.60 (Multi-source crypto stack — HACL\* + libcrux + RustCrypto)
* **Sprint:** Sprint 1.1 / Phase 1.1.D.2.a (toolchain integration); contracts land progressively in Phases 1.1.D.2.b (hash family), 1.1.D.2.c (HMAC + KDF + Argon2id), 1.1.D.3 (AEAD + signatures), 1.1.D.4 (KEM + ML-DSA + hybrids)
* **Supersedes:** none (operationalises Decision 2.20's "Creusot" half)

## Context

Decision 2.20 pairs **Creusot** (Rust function-contract prover) with **TLA+** (protocol-level specification). The two cover disjoint verification surfaces: TLA+ proves that distributed/concurrent protocols preserve invariants across all interleavings (e.g. `spec/crypto.tla` proves the key-lifecycle state machine in Phase 1.1.D.1); Creusot proves that individual Rust functions preserve pre/post-conditions and loop invariants for all admissible inputs.

Per Section XII success metrics, Pulsar Framework targets **≥ 80 % Creusot contract coverage across kernel functions** at GA. Section 17.27 narrows scope to:

* **Kernel crates** (`pulsar-kernel`): Creusot contracts on every public function where tractable.
* **Security controls** (`pulsar-guard`): Creusot contracts on verification functions (CSRF token generation/verification, SRI digest invariants, rate-limiter decision functions).
* **`pulsar-dataprotection` RtbF two-phase commit**: Creusot contracts plus property tests on commit + abort invariants.
* **All other crates**: property-based testing as the formal-discipline floor; formal verification optional.

Creusot's 2026 maturity is significantly stronger than its 2024 baseline but retains known limitations relevant to `pulsar-kernel`'s actual code:

1. **FFI boundary** — Creusot does not reason about `extern "C"` calls; the HACL\* primitive surface (per ADR-0009) is a hard boundary. Contracts on the Rust-side safe wrappers must `trust` the FFI calls.
2. **`zeroize`-derive proc-macros** — proc-macro-generated `Drop` impls are typically marked trusted.
3. **`async fn`** — partial support; the kernel does not currently expose async crypto APIs, so this is non-blocking for Phase 1.1.D.
4. **`SecretBox<T>` / `secrecy` patterns** — opaque to Creusot's logical reasoning; require trust annotations on the `expose_secret` + drop boundaries.

The remaining surface (length invariants, idempotence, deterministic-input → deterministic-output, output-buffer-size postconditions, error-path completeness) is firmly inside Creusot's expressiveness and constitutes the bulk of `pulsar-kernel::crypto`'s public API.

## Decision

The Pulsar Framework adopts **Creusot v0.11.0** (released 2026-04-20) as the function-contract prover for `pulsar-kernel`, `pulsar-guard`, and the `pulsar-dataprotection` RtbF two-phase commit. The Rust-side specification language is **Pearlite** (Creusot's embedded specification syntax, exposed via the `creusot-std` crate).

**Toolchain pin:**

* **Creusot** v0.11.0 (commit-pinned in CI; `tools/scripts/install-creusot.sh` is the canonical install path)
* **Rust nightly** `nightly-2026-04-21` (matches Creusot v0.11.0's `rust-toolchain` file)
* **Why3** v1.6.0+ (intermediate-language platform; `apt install why3` on Ubuntu 24.04 — version verified compatible with Creusot v0.11)
* **SMT solvers**: Z3 v4.8.12+, CVC5 v1.1.2+ (both via `apt`); Alt-Ergo via `opam install alt-ergo` (preferred for some quantifier-heavy proofs but not strictly required)
* **`creusot-std`** v0.11.0 (Pearlite specification macros — workspace dev-dependency, the public-facing crate that ships specifications + macros for the Rust standard library; supersedes the pre-v0.11 `creusot-contracts` published crate). Macros expand to no-ops when the `creusot` cfg flag is not set, so production builds carry zero runtime overhead.

**Integration shape:**

1. **Workspace dep** — `creusot-std = "0.11"` declared in `[workspace.dependencies]`; consumed by `pulsar-kernel` as a *regular* (non-optional) dependency. The `cfg_attr` + `optional = true` + feature-flag pattern attempted in Phase 1.1.D.2.a is rejected per the empirical CI failure on 2026-04-29: `cargo-creusot`'s `get_contracts_version` (cargo-creusot/src/main.rs:get_contracts_version) reads `cargo metadata` *without* activating any features, so an optional-feature-gated `creusot-std` is never seen by the proof driver and `cargo creusot prove` errors with `creusot-std not found in dependencies`. Cost of the unconditional dep: the proc-macro crate (~300 KB) compiles once; on stable rustc the macros expand to no-ops outside `cfg(creusot)`, so production binaries carry zero runtime overhead.
2. **Macro import** — every contract-bearing module imports the specific macros it uses: `use creusot_std::macros::{ensures, trusted};`. The full `creusot_std::prelude::*` glob is avoided because it shadows std's `vec!` macro and several derive macros (`Clone`, `PartialEq`, `Default`) — the explicit-macro import keeps existing kernel code unaffected by the new dep.
3. **CI workflow** — `.github/workflows/creusot.yml` runs `build` (auto on every kernel-touching PR — `cargo check -p pulsar-kernel`) and `proof-discharge` (auto on every kernel-touching PR since 1.1.D.2.b — installs the toolchain and runs `cargo creusot prove`). Path-filtered to `crates/pulsar-kernel/**` + the workflow + the install script, so non-kernel PRs don't pay the cost.
4. **Trust annotations** — functions calling HACL\* FFI carry `#[trusted]` (imported from `creusot_std::macros`) with a comment naming the upstream verification claim being relied on (e.g. *"trusts HACL\* SHA-256 implementation per F\* verification, ADR-0009"*). The trusted set is audited at the Phase 1.1.E sprint exit gate.
5. **Roadmap** — contracts land progressively per the D.2.a / D.2.b / D.2.c / D.3 / D.4 micro-slicing of Phase 1.1.D. Phase 1.1.E exit gate measures coverage and enforces the ≥ 80 % threshold.

**What lives inside Creusot contracts:**

* Output-buffer length invariants (e.g. `sha256(M)` always returns 32 bytes)
* Determinism (deterministic primitives like Ed25519 sign per RFC 8032 § 5.1.6 — same `(sk, M)` → same signature)
* Error-path completeness (every non-`Ok(())` return is documented + reachable from a precondition violation)
* Lifecycle invariants matching `spec/crypto.tla` at the function boundary (e.g. a Zeroised key cannot be Used)
* Successor-uniqueness invariants for rotation (matching `SuccessorIsUnique` from `spec/crypto.tla`)

**What does NOT live inside Creusot:**

* Cryptographic-primitive correctness — anchored in HACL\* / libcrux F\* + hax verification (ADR-0009 + Decision 2.60); Creusot annotates the Rust-side wrappers, not the underlying C / Rust primitive implementations
* Constant-time / side-channel properties — Creusot does not model timing channels; HACL\* + `subtle` provide constant-time guarantees at the primitive level
* Distributed / concurrent protocol invariants — those live in TLA+ specs (`spec/*.tla`), per Decision 2.59
* Capability-token unforgeability + audit-chain append-only — those live in SPARK 2014 (`services/spark-invariants/`, ADR-0010); Creusot's quantifier support in 2026 cannot reach the cross-function-quantified invariants there

## Consequences

### Positive

* `pulsar-kernel` function contracts gain machine-checked proof tier — strictly stronger than property-based testing on the same surface.
* Closes Section XII success-metric gap (≥ 80 % kernel contract coverage at GA).
* Aligns with Decision 2.20's stated tooling — operationalises what the plan already commits to.
* Pearlite specifications are co-located with the Rust source — readers of `pulsar-kernel/src/crypto/hash.rs` see the contracts inline with the implementation, not in a separate proof-environment file (vs Coq / Lean external-proof approach).
* Procurement evaluation (FedRAMP / NIS2 / DORA / EU CRA Annex II) benefits — formal verification at the kernel-function level is a top-of-funnel maturity signal that audit-only competitors cannot match.

### Negative

* CI runtime — Creusot model-checking adds ~5-15 minutes per kernel-touching PR (cached toolchain install + per-function proof discharge). Mitigated by path-filtering the workflow to kernel-only paths so non-kernel PRs are unaffected.
* Build pipeline gains opam + Why3 + Z3 + CVC5 + Creusot dependencies. Mitigated by `tools/scripts/install-creusot.sh` automating the install and by CI caching the compiled toolchain.
* Contributor learning curve — Pearlite specification syntax is non-trivial. Mitigated by progressive scope expansion (Phase 1.1.D micro-slicing) and by examples in `docs/development/creusot-std.md` (lands with Phase 1.1.D.2.a).
* Trust annotations on FFI surface — every `#[creusot::trusted]` is a manual gap in the proof. Mitigated by requiring each `trusted` annotation to cite the upstream verification claim it relies on (HACL\* F\*, libcrux hax, etc.) and by auditing the trusted set in the Phase 1.1.E sprint exit gate.
* Toolchain version pin couples Pulsar to Creusot's nightly cadence — bumping Creusot requires testing against the matching nightly. Standard tooling-pin tradeoff.

### Neutral

* Creusot proofs do not replace property tests, fuzz tests, or KAT vectors — those continue to provide the operational testing surface. Creusot provides the inductive proof tier on top.
* Pulsar's existing TLA+ specifications (post-Phase 1.1.D.1) and SPARK 2014 invariants (post-Sprint 1.2 per ADR-0010) compose with Creusot to form the three-tier formal-verification stack: TLA+ for protocols, Creusot for functions, SPARK for catastrophic-blast-radius invariants requiring cross-function quantification.

## Alternatives considered

* **Prusti** (Viper-backed Rust verifier).
  Rejected: tooling maturity in 2026 is lower than Creusot; smaller community; weaker support for the user-defined-types + ghost-state patterns that crypto wrappers need. Creusot v0.11.0 is the stable choice for 2026Q2.
* **Kani** (bounded model checker for Rust).
  Rejected: bounded model checking only — proves properties up to a finite bound, not for all admissible inputs. Insufficient for the inductive proofs Section XII targets. Kani remains useful as a complementary tool for specific properties; not adopted as the primary contract prover.
* **F\* directly** (writing the contracts in F\* and extracting Rust).
  Rejected: would require translating large portions of `pulsar-kernel` to F\* + maintaining the extraction. HACL\* + libcrux already use this pattern at the primitive level; doing it at the wrapper level would multiply maintenance cost.
* **Alloy** (relational specification language).
  Rejected: weaker temporal logic than TLA+ for protocol-level specs; not a function-contract prover; not in scope for the "function contract" surface this ADR addresses.
* **Coq / Lean external proof environments**.
  Rejected: proof remains separated from the Rust source — readers of the implementation cannot see the contracts inline. Pearlite (Creusot's in-source DSL) keeps spec + impl co-located.
* **Property-based testing only** (no formal verification on the kernel function surface).
  Rejected: contradicts Decision 2.20 + Section XII success metrics + the regulated-domain ambition. Property testing remains the floor for non-kernel crates per Section 17.27 but is not sufficient for the kernel.
* **Defer Creusot adoption to post-1.0.0 GA**.
  Rejected: contradicts Decision 2.20 + Section XII; would require a major version bump to introduce verification at GA. The ≥ 80 % coverage threshold is tractable inside Sprint 1.1's Phase 1.1.D timeline.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.20 (Creusot + TLA+), Section XII success metrics (≥ 80 % Creusot contract coverage; 15 TLA+ specs), Section 17.27 (formal verification scope per crate)
* Risk register entries: R-001 (Creusot prover coverage gap on FFI / proc-macros — mitigated by trust annotations + HACL\* / libcrux upstream verification claims)
* Related ADRs: ADR-0003 (microkernel formal verification), ADR-0009 (HACL\* via FFI — Creusot contracts annotate the Rust-side wrappers, not the C primitives), ADR-0010 (SPARK 2014 for catastrophic-blast-radius invariants — disjoint scope from Creusot per the discussion above)
* External:
  * Creusot project: <https://github.com/creusot-rs/creusot>
  * Creusot user guide: <https://creusot-rs.github.io/creusot/>
  * Pearlite reference: <https://creusot-rs.github.io/creusot/lang/pearlite>
  * Why3 platform: <https://www.why3.org/>
  * Denis, X., Jourdan, J.-H., Marché, C. *"Creusot: A Foundry for the Deductive Verification of Rust Programs"* — ICFEM 2022.

## Compliance mapping

The Creusot adoption strengthens regulatory-evaluation posture for clauses that explicitly reference formal-verification artefacts:

* **NIST SP 800-53 Rev. 5 SC-13** (cryptographic protection) — formally-verified function contracts on the cryptographic-wrapper layer complement HACL\* / libcrux primitive verification.
* **EU CRA (Regulation (EU) 2024/2847) Annex I § 1(d)** — "designed, developed and produced to limit attack surfaces"; function-level proofs are the strongest available "limit attack surfaces" mechanism for the wrapper layer.
* **Common Criteria EAL 6+ / EAL 7** (Decision 2.52 target) — formal proof at the function-contract level is part of the "semi-formally verified design" (EAL 6) and "formally verified design" (EAL 7) tiers.
* **DORA Art. 9(2)** (preventive measures including authentication of users) — function-contract proofs reduce defect probability in the cryptographic wrappers that authenticate users.
* **NIST FIPS 140-3 Level 2** (Decision 2.56 target — Sprint 4.7) — Creusot proofs strengthen the CMVP submission; the Cryptographic Module Validation Program considers formal-verification claims as supporting evidence.
