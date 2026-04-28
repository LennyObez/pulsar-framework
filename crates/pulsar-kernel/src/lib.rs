//! Formally verified microkernel: crypto, audit chain, session, router, middleware, DI.
//!
//! Per Decision 2.20 + 2.53 + 2.60 + ADR-0003, this crate is the
//! verification core of Pulsar Framework. Sub-modules ship per the
//! Sprint 1.x phasing in `docs/plan.md` Section V:
//!
//! - [`crypto`] — Cryptographic primitives via HACL\* (classical) +
//!   libcrux (PQC) + RustCrypto (Argon2id). Sprint 1.1.
//! - `audit` — Ed25519 + Merkle + RFC 6962 transparency log. Sprint 1.2.
//! - `session` — Typed-state session machine. Sprint 1.3.
//! - `router` — Radix-tree path resolution. Sprint 1.4.
//! - `middleware` — Typed pipeline with compile-time ordering. Sprint 1.5.
//! - `container` — Compile-time DI container. Sprint 1.6.
//!
//! Phase 1.1.B.1 lands the [`crypto::hash`] family (SHA-2, SHA-3,
//! BLAKE2). Subsequent phases extend the surface incrementally.

pub mod crypto;
pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
