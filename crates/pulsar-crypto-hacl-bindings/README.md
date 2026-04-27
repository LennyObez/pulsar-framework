# pulsar-crypto-hacl-bindings

Rust FFI bindings to **HACL\*** — the formally verified cryptographic library written in F\* and extracted to constant-time C. Wraps AEAD, KDF, signatures, hashes, and ML-KEM/ML-DSA primitives that `pulsar-kernel` re-exports under safety wrappers + Creusot contracts.

## What

A `*-sys`-style crate that vendors the HACL\* C distribution + provides Rust FFI declarations for each primitive Pulsar uses:

* AEAD: AES-128-GCM, AES-256-GCM, ChaCha20-Poly1305.
* Key agreement: Curve25519 (X25519), P-256 (NIST).
* Signatures: Ed25519, ML-DSA-65, ML-DSA-87 (NIST FIPS 204).
* KEM: ML-KEM-768, ML-KEM-1024 (NIST FIPS 203).
* Hashes: SHA-2-256/384/512, SHA-3-256/384/512, BLAKE2b, BLAKE2s.
* KDFs: HKDF (over SHA-2 family), HMAC.

## Why

HACL\* is the only widely-deployed cryptographic library that ships **machine-verified proofs of correctness, memory safety, and secret independence (constant-time)** for every primitive. Production users include Mozilla Firefox NSS (TLS), Linux kernel WireGuard (Curve25519), Tezos blockchain, Microsoft Azure VPN, ZcashFoundation. By comparison, `ring` is rigorously audited (NCC Group + Trail of Bits) but not formally verified.

Pulsar adopts HACL\* per Decision 2.53 to close the "audited" → "formally verified" gap on its cryptographic surface. This is the marginal improvement that puts Pulsar's cryptographic assurance on the seL4 + HACL\* tier — the highest-rigour combination achievable in the Rust ecosystem in 2026 without rewriting tokio + hyper + sqlx in Ada/SPARK (per Decision 2.54 rejected alternative).

## How

`pulsar-crypto-hacl-bindings` is a v2.3 sibling crate to `pulsar-kernel` introduced per Decision 2.53. It is structured as a `*-sys` crate (per Rust ecosystem convention for C-binding crates):

* `hacl-c/` — vendored copy of the HACL\* C distribution, pinned to a release tag for SLSA Level 4 reproducibility per Decision 2.57.
* `build.rs` — compiles the C sources via `cc`, generates Rust bindings via `bindgen`.
* `src/lib.rs` — re-exports the `bindgen`-generated FFI declarations under typed Rust namespaces.
* No safe Rust API surface — `pulsar-kernel` provides the safe wrappers + Creusot contracts + capability gating.

At Phase 0 the `hacl-c/` directory is a placeholder; the actual vendoring lands at Sprint 1.1 along with the `pulsar-kernel::crypto` safe wrappers.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 1.1. See [`docs/plan.md`](../../docs/plan.md) Section II Decision 2.53 + Section IV.2.5 + Section V.1.1.

## Licence

Apache-2.0 for the Rust binding code; HACL\* itself is Apache-2.0 (per the HACL\* upstream repository). See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).

## References

* HACL\* upstream: <https://github.com/hacl-star/hacl-star>
* Mozilla NSS HACL\* integration: <https://hg.mozilla.org/projects/nss/file/tip/lib/freebl/verified>
* Linux WireGuard Curve25519 (HACL\*-derived): linux kernel `lib/crypto/curve25519-hacl64.c`
* HACL\* paper: Polubelova, M. et al. "HACLxN: Verified Generic SIMD Crypto." CCS '20.
* F\* language: <https://fstar-lang.org>
