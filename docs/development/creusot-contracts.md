# Creusot function contracts — developer guide

This guide documents how to author, build, and discharge Creusot function contracts in the Pulsar Framework. Decision context lives in **ADR-0015** (Creusot for kernel function contracts) + plan Section II Decision 2.20 (Creusot + TLA+).

> **Status:** Phase 1.1.D.2.a (toolchain skeleton + smoke-test contract). Production contracts on hash / HMAC / KDF / AEAD / signatures / KEM / hybrids land progressively in Phases 1.1.D.2.b → 1.1.D.4.

## Overview

Creusot is a deductive-verification tool for Rust. Function contracts are written in **Pearlite** (an embedded specification syntax provided by the `creusot-std` crate) and discharged by translating Rust to Why3 + dispatching proof obligations to SMT solvers (Z3, CVC5, Alt-Ergo).

In Pulsar:

* Contracts are **gated behind the `formal-verification` Cargo feature** on `pulsar-kernel`. With the feature off, `creusot-std` is not pulled in and contract attributes are skipped via `cfg_attr` — production builds carry zero overhead.
* Proof discharge requires the **Creusot driver** (`cargo creusot prove`) which is NOT shipped via crates.io; it lives in the `creusot-rs/creusot` GitHub workspace and is installed via the canonical `./INSTALL` script.
* The CI workflow `.github/workflows/creusot.yml` runs:
  - `feature-flag-build` automatically — verifies `cargo check -p pulsar-kernel --features formal-verification` is clean
  - `proof-discharge` on `workflow_dispatch` only (Phase 1.1.D.2.a — flips to auto-trigger when production contracts land in Phase 1.1.D.2.b)

## Local installation (one-time)

The `tools/scripts/install-creusot.sh` script automates the full install on Ubuntu 24.04+:

```bash
./tools/scripts/install-creusot.sh
```

The script is idempotent. First-run cost is ~15-25 minutes (opam compiles OCaml + Why3 + provers; Creusot driver is built from source).

Prerequisites:

* `sudo` access (for the apt step that installs `opam`, `libzmq3-dev`, `z3`, `cvc5`)
* `rustup` with at least one stable toolchain installed
* `git`

The script provisions:

| Component | Source | Version |
|---|---|---|
| `opam` | apt | ≥ 2.1 |
| `libzmq3-dev` | apt | system |
| `z3` | apt | ≥ 4.8 |
| `cvc5` | apt | ≥ 1.1 |
| Creusot driver (`cargo creusot`, `creusot-rustc`) | git clone + ./INSTALL | v0.11.0 |
| Why3 + Why3find | opam (via Creusot's INSTALL) | matched to v0.11.0 |
| Rust nightly | rustup (auto via Creusot's `rust-toolchain`) | nightly-2026-04-21 |

After install, verify:

```bash
cargo creusot --help       # should print the Creusot subcommand help
which creusot-rustc        # should be in $HOME/.local/bin or similar
```

## Authoring contracts

### Boilerplate

Every module that uses Creusot contracts gates the import behind the feature flag:

```rust
#[cfg(feature = "formal-verification")]
use creusot_std::prelude::*;
```

Each contract attribute is gated via `cfg_attr` so it is only applied when the feature is active:

```rust
#[cfg_attr(
    feature = "formal-verification",
    ::creusot_std::macros::ensures(result == 32usize || result == 48usize || result == 64usize)
)]
pub const fn digest_len(self) -> usize {
    match self {
        Self::Sha256 | Self::Sha3_256 | Self::Blake2s256 => 32,
        Self::Sha384 | Self::Sha3_384 => 48,
        Self::Sha512 | Self::Sha3_512 | Self::Blake2b512 => 64,
    }
}
```

The fully-qualified path (`::creusot_std::macros::ensures`) avoids needing a glob `use` at the contract site, which keeps non-formal-verification builds free of unused-import warnings.

### Common attributes

| Attribute | Purpose |
|---|---|
| `#[requires(P)]` | Pre-condition `P` must hold for callers to invoke the function safely |
| `#[ensures(Q)]` | Post-condition `Q` holds when the function returns |
| `#[ensures(\|x\| Q(x))]` | Post-condition with explicit return-value name |
| `#[invariant(I)]` | Loop invariant `I` |
| `#[variant(V)]` | Loop / recursion termination measure (decreases on each iteration) |
| `#[creusot::trusted]` | Mark the function as trusted — no proof obligations are generated. Use sparingly + cite the upstream verification claim being relied on (e.g. HACL\* F\* proofs for FFI-bridge functions) |

### Pearlite syntax notes

* `result` — the return value
* `result@` — the *model* of the return value (mathematical view; e.g. for `Vec<T>`, the model is the underlying `Seq<T>`)
* `^x` — final value of a `&mut` borrow (relevant for invariants over mutated references)
* `forall<x: T> P(x)` — universal quantifier
* `exists<x: T> P(x)` — existential quantifier
* `if P { Q } else { R }` — conditional inside specifications

The Creusot guide (<https://guide.creusot.rs>) is the authoritative reference for syntax; ADR-0015 is the policy reference.

## Discharging proofs locally

Once the toolchain is installed:

```bash
cd crates/pulsar-kernel
cargo creusot --features formal-verification prove
```

Discharge time scales with contract count + complexity. A clean `pulsar-kernel` discharge with the smoke-test contract (Phase 1.1.D.2.a) takes < 30 seconds.

## Trusted boundaries (FFI, macros)

Some Pulsar code cannot be proven by Creusot v0.11 directly:

1. **HACL\* FFI calls** — `unsafe extern "C"` cannot be reasoned about. Each safe wrapper that crosses the FFI boundary carries `#[creusot::trusted]` with a comment naming the upstream verification claim:

   ```rust
   #[cfg_attr(feature = "formal-verification", ::creusot_std::macros::trusted)]
   /// Trusted: relies on HACL* F* verification of EverCrypt_Hash_Incremental_hash
   /// per ADR-0009. The Rust safe wrapper enforces the FFI preconditions
   /// (output buffer size + input length range) before the unsafe call.
   pub fn hash_into_slice(...) { ... }
   ```

2. **`zeroize`-derive proc-macro generated `Drop`** — the macro-expanded `Drop` impl is opaque to Creusot. Trust the proc-macro semantics + cite the upstream documented behaviour.

3. **`secrecy::SecretBox<T>` access patterns** — the `expose_secret` boundary is the trust point.

Each `#[trusted]` annotation must be justified inline; the Phase 1.1.E sprint exit gate audits the trusted set + verifies the cited upstream claims are still in force.

## Coverage target

Per Section XII success metrics: **≥ 80 % Creusot contract coverage on kernel functions at GA**. Phase 1.1.D.2.a establishes the integration; coverage builds up through D.2.b / D.2.c / D.3 / D.4 and is measured at the Phase 1.1.E exit gate.

## References

* **ADR-0015** — Creusot for kernel function contracts (Pulsar policy)
* **Plan** Section II Decision 2.20 (Creusot + TLA+); Section XII success metrics; Section 17.27 (formal-verification scope per crate)
* **Creusot upstream**:
  - Project: <https://github.com/creusot-rs/creusot>
  - User guide: <https://guide.creusot.rs>
  - Pearlite reference: <https://guide.creusot.rs/pearlite>
* **Pulsar adjacent verification**:
  - `spec/crypto.tla` (Phase 1.1.D.1) — protocol-level state machine
  - `services/spark-invariants/` (ADR-0010, Sprint 1.2+) — SPARK 2014 for capability + audit-chain invariants
  - HACL\* (ADR-0009) — verified primitive layer
