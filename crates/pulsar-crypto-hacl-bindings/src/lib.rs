//! Rust FFI bindings to HACL* — formally verified cryptographic primitives.
//!
//! HACL* is the only widely-deployed cryptographic library that ships
//! machine-verified proofs of correctness, memory safety, and secret
//! independence (constant-time) for every primitive. Production users:
//! Mozilla Firefox NSS (TLS), Linux kernel WireGuard (Curve25519), Tezos
//! blockchain, Microsoft Azure VPN, ZcashFoundation.
//!
//! This crate provides only the raw FFI surface. Safe Rust wrappers,
//! Creusot contracts, and capability gating live in `pulsar-kernel`.
//!
//! Adopted per Decision 2.53 (v2.3 lock-in) to close the "audited" →
//! "formally verified" gap on Pulsar's cryptographic surface. See
//! `docs/plan.md` Section II Decision 2.53 + Section IV.2.5 + Section V.1.1.
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
