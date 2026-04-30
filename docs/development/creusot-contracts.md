# Creusot function contracts — developer guide

This guide documents how to author, build, and discharge Creusot function contracts in the Pulsar Framework. Decision context lives in **ADR-0015** (Creusot for kernel function contracts) + plan Section II Decision 2.20 (Creusot + TLA+).

> **Status:** Phase 1.1.D.2.b (production contracts on the hash family + cargo-feature-flag pattern dropped per the empirical CI failure on Phase 1.1.D.2.a). Production contracts on HMAC / KDF / AEAD / signatures / KEM / hybrids land progressively in Phases 1.1.D.2.c → 1.1.D.4.

## Overview

Creusot is a deductive-verification tool for Rust. Function contracts are written in **Pearlite** (an embedded specification syntax provided by the `creusot-std` crate) and discharged by translating Rust to Why3 + dispatching proof obligations to SMT solvers (Z3, CVC5, Alt-Ergo).

In Pulsar:

* `creusot-std` is a **regular (non-optional) workspace dependency** of `pulsar-kernel`. The proc-macro crate (~300 KB) compiles once; on stable rustc the `#[ensures]` / `#[requires]` / `#[trusted]` macros expand to no-ops outside `cfg(creusot)`, so production binaries carry zero runtime overhead. The `cfg_attr` + `optional = true` + feature-flag pattern attempted in Phase 1.1.D.2.a was rejected because `cargo-creusot`'s `get_contracts_version` reads `cargo metadata` without activating any features — an optional-feature-gated `creusot-std` is invisible to the proof driver.
* Proof discharge requires the **Creusot driver** (`cargo creusot prove`) which is NOT shipped via crates.io; it lives in the `creusot-rs/creusot` GitHub workspace and is installed via the canonical `./INSTALL` script (mirrored by `tools/scripts/install-creusot.sh`).
* The CI workflow `.github/workflows/creusot.yml` runs:
  - `build` automatically — verifies `cargo check -p pulsar-kernel` is clean (catches contract-syntax regressions cheaply)
  - `proof-discharge` automatically (since 1.1.D.2.b) — installs the toolchain (cached) and runs `cargo creusot prove` on every kernel-touching PR

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

Every module that uses Creusot contracts imports the specific macros it needs at the top:

```rust
use creusot_std::macros::{ensures, trusted};
// add `requires`, `invariant`, `variant`, `logic`, etc. as needed
```

The full `creusot_std::prelude::*` glob is avoided — it shadows std's `vec!` macro and several derive macros (`Clone`, `PartialEq`, `Default`), which conflicts with existing kernel code. The explicit-macro import keeps the contract surface explicit and the std-prelude identifiers untouched.

Each contract attribute is then applied directly without any cfg-gating:

```rust
#[ensures((self == HashAlgorithm::Sha256) ==> result == 32usize)]
#[ensures((self == HashAlgorithm::Sha384) ==> result == 48usize)]
// ... one ensures per variant
pub const fn digest_len(self) -> usize {
    match self {
        Self::Sha256 | Self::Sha3_256 | Self::Blake2s256 => 32,
        Self::Sha384 | Self::Sha3_384 => 48,
        Self::Sha512 | Self::Sha3_512 | Self::Blake2b512 => 64,
    }
}
```

On stable rustc the macros expand to no-ops; only `cargo creusot prove` actually evaluates the Pearlite expressions inside the attributes.

### Common attributes

| Attribute | Purpose |
|---|---|
| `#[requires(P)]` | Pre-condition `P` must hold for callers to invoke the function safely |
| `#[ensures(Q)]` | Post-condition `Q` holds when the function returns |
| `#[ensures(\|x\| Q(x))]` | Post-condition with explicit return-value name |
| `#[invariant(I)]` | Loop invariant `I` |
| `#[variant(V)]` | Loop / recursion termination measure (decreases on each iteration) |
| `#[trusted]` | Mark the function as trusted — no proof obligations are generated. Use sparingly + cite the upstream verification claim being relied on (e.g. HACL\* F\* proofs for FFI-bridge functions) |
| `#[logic]` | Mark the function as a pure logical / ghost function callable from contracts |

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
cargo creusot prove
```

Discharge time scales with contract count + complexity. A clean `pulsar-kernel` discharge with the Phase 1.1.D.2.b hash-family contracts takes < 60 seconds.

## Trusted boundaries (FFI, macros)

Some Pulsar code cannot be proven by Creusot v0.11 directly:

1. **HACL\* FFI calls** — `unsafe extern "C"` cannot be reasoned about. Each safe wrapper that crosses the FFI boundary carries `#[trusted]` with a comment naming the upstream verification claim:

   ```rust
   #[trusted]
   #[ensures(forall<v: Vec<u8>> result == Ok(v) ==> v@.len() == algo.digest_len()@)]
   /// Trusted: relies on HACL* F* verification of EverCrypt_Hash_Incremental_hash
   /// per ADR-0009. The Rust safe wrapper enforces the FFI preconditions
   /// (output buffer size + input length range) before the unsafe call.
   pub fn hash(algo: HashAlgorithm, input: &[u8]) -> Result<Vec<u8>> { ... }
   ```

   The postcondition is meaningful even though the function is trusted — callers see and rely on it; trust applies only to proving that the body actually upholds the postcondition (Creusot accepts that as an axiom rather than checking the FFI body).

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
