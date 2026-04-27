//! Rust FFI bindings to HACL* — formally verified classical cryptographic primitives.
//!
//! HACL* is the only widely-deployed cryptographic library that ships
//! machine-verified proofs of correctness, memory safety, and secret
//! independence (constant-time) for every primitive in the classical
//! surface. Production users: Mozilla Firefox NSS (TLS), Linux kernel
//! WireGuard (Curve25519), Tezos blockchain, Microsoft Azure VPN,
//! Zcash Foundation.
//!
//! This crate covers the **twelve classical primitives** Pulsar uses:
//! AES-128/256-GCM, ChaCha20-Poly1305, Curve25519 (X25519), P-256,
//! Ed25519, SHA-2-256/384/512, SHA-3-256/384/512, BLAKE2b, BLAKE2s,
//! HKDF (over SHA-2 family), HMAC. The four NIST PQC primitives
//! (ML-KEM-768/1024, ML-DSA-65/87) are sourced from libcrux Rust-native
//! crates per Decision 2.60 — they do NOT flow through this FFI crate.
//! Argon2id is sourced from RustCrypto `argon2` per Decision 2.60.
//!
//! This crate provides only the raw FFI surface. Safe Rust wrappers,
//! Creusot contracts, and capability gating live in `pulsar-kernel`.
//!
//! Adopted per Decision 2.53 + Decision 2.60 (v2.3 lock-in) to close the
//! "audited" → "formally verified" gap on Pulsar's classical cryptographic
//! surface while preserving verification provenance for the PQC surface
//! through the sibling Cryspen libcrux ecosystem. See `docs/plan.md`
//! Section II Decision 2.53 + 2.60 + Section IV.2.5 + Section V.1.1.
//!
//! At Phase 0 the HACL* C distribution is a placeholder; the vendoring +
//! actual FFI declarations land at Sprint 1.1 along with `pulsar-kernel::crypto`
//! safe wrappers.

#![allow(non_camel_case_types, non_snake_case, non_upper_case_globals)]
#![cfg_attr(hacl_placeholder, allow(dead_code))]

pub mod error;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");

#[cfg(hacl_placeholder)]
mod placeholder {
    //! Phase 0 placeholder — Sprint 1.1 replaces this with `bindgen`-generated FFI declarations.
}
