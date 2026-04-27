# pulsar-spark-invariants

Ada/SPARK 2014 module formally proving the two highest-assurance kernel invariants per Decision 2.54 (v2.3 lock-in):

* **Capability token unforgeability** — no execution path produces a `Capability_Token` that `Verify_Capability` accepts unless that token was previously minted by `Mint_Capability` with a precondition restricting callers.
* **Audit chain append-only enforcement** — for any `(Prev_Root, Entry, New_Root)` triple, `New_Root` is the **unique** RFC 6962 Merkle root extending `Prev_Root` with `Entry`. No other `New_Root` value satisfies the verifier postcondition.

## Why SPARK and not Rust + Creusot for these invariants?

Both invariants involve cross-function quantification (`for all`) over an abstract membership / hash-extension predicate that Creusot 2026 cannot prove with the same level of automation that GNATprove provides. SPARK 2014 + GNATprove ship native support for:

* **Quantified contracts** with full SMT backing (Z3, CVC5, Alt-Ergo).
* **Refinement of abstract types** via `Ghost` predicates that exist only at proof time.
* **Information-flow analysis** — `Global =>` clauses statically guarantee no side-channel leak through global state.
* **Provable absence of runtime errors** by default (overflow, division-by-zero, dereference of null, array OOB).

These four axes collectively put SPARK at a strictly higher tier than Rust + Creusot for these specific invariants.

## Why not SPARK for the entire kernel?

`pulsar-spark-invariants` is **the smallest possible SPARK footprint** consistent with state-of-art assurance:

* No Ada/SPARK web-framework ecosystem exists (no equivalent to tokio, hyper, sqlx, ring). Rewriting these in SPARK would multiply maintainer effort by 5–10 person-years.
* SPARK developer talent is rare (~5–10k worldwide vs ~3M Rust developers per Stack Overflow Survey 2025).
* The polyglot is acceptable per Decision 2.46 (Go for `services/operator`) and Decision 2.8 (native HTML5 + Web Components for `services/admin`). Pulsar already accepts polyglot when the best tool for a specific surface is not Rust.

So SPARK is reserved for the two invariants where Rust + Creusot cannot match the proof tier and the surface is bounded enough that the FFI cost is negligible (the SPARK functions are pure verifiers — immutable inputs, boolean output, no allocation, no I/O).

## Build

```bash
gprbuild -P pulsar_invariants.gpr
```

Produces `lib/libpulsar_invariants.a` linked into `pulsar-kernel` via the FFI declarations in `pulsar-crypto-hacl-bindings::ffi::spark`.

## Prove

```bash
gnatprove -P pulsar_invariants.gpr -j0 --level=4 --prover=z3,cvc5,altergo
```

CI runs this on every commit touching either `src/` or the consuming kernel paths. Sprint 1.2 (capability + audit kernel sprint) requires a clean GNATprove report at sprint exit per Decision 2.20 formal-verification scope.

## Toolchain

* **GNAT Community 2026** (or GNAT Pro for production builds).
* **GNATprove 2026** (free + commercial tier).
* **Alire** for any third-party Ada dependencies (currently none — module is self-contained).
* **Z3** + **CVC5** + **Alt-Ergo** SMT solvers (provided with GNATprove).

Reproducible-build pipeline: GNAT Community 2026 image is pinned in `flake.nix` per Decision 2.57 (SLSA Level 4 reproducibility).

## Status

**Phase 0 placeholder.** The package specs declare the contracts; the bodies are stubs that return "verification declined" pending Sprint 1.2 (capability + audit kernel sprint). The FFI symbols `pulsar_spark_verify_capability` + `pulsar_spark_verify_audit_append` exist but always return 0.

## Licence

Apache-2.0 for the SPARK code; see the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).

## References

* SPARK 2014 Reference Manual: <https://docs.adacore.com/spark2014-docs/html/lrm/>
* GNATprove user guide: <https://docs.adacore.com/spark2014-docs/html/ug/>
* RFC 6962 (Certificate Transparency Merkle tree): <https://datatracker.ietf.org/doc/html/rfc6962>
* HACL\* upstream (the cryptographic primitives the SPARK module relies on): <https://github.com/hacl-star/hacl-star>
* Klein et al. "seL4: Formal Verification of an OS Kernel." CACM 2010 (the methodology this module borrows).
