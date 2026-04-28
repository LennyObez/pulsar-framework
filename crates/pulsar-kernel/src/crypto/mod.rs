//! Cryptographic primitive surface — safe Rust wrappers over the HACL\* FFI
//! per Decision 2.53 + 2.60 + ADR-0009.
//!
//! Each sub-module wraps one primitive family with typed inputs/outputs
//! and lifecycle invariants enforced by the type system:
//!
//! - [`hash`]   — SHA-2 (256/384/512), SHA-3 (256/384/512), BLAKE2b/2s
//! - [`aead`]   — AES-128/256-GCM, ChaCha20-Poly1305
//! - `signature` — Ed25519 (Phase 1.1.B.3); ML-DSA-65 hybrid via libcrux (Phase 1.1.C)
//! - `kem`      — X25519 (Phase 1.1.B.3); ML-KEM-768 hybrid via libcrux (Phase 1.1.C)
//! - `hkdf`     — HKDF over SHA-2 family (Phase 1.1.B.4)
//! - `hmac`     — HMAC over SHA-2 + BLAKE2 family (Phase 1.1.B.4)
//! - `argon2`   — Argon2id password hashing via RustCrypto (Phase 1.1.B.4)
//!
//! The surface is intentionally agile: every primitive family has an
//! algorithm-parameterised public API plus algorithm-specific convenience
//! aliases (`sha256`, `sha512`, etc.) for the common case. Algorithm
//! enums are `#[non_exhaustive]` so sprint phases can extend support
//! without breaking downstream `match` exhaustiveness assumptions.
//!
//! All functions ensure HACL\*'s runtime CPU-feature dispatcher
//! (`EverCrypt_AutoConfig2_init`) has been initialised exactly once per
//! process via [`std::sync::Once`] before any primitive is invoked. The
//! initialisation is idempotent at the C layer; the `Once` wrapper
//! avoids redundant atomic synchronisation on the hot path.
//!
//! **Deprecated algorithms (MD5, SHA-1) are deliberately absent from
//! every public enum**, even though the underlying FFI compiles them
//! to satisfy the EverCrypt dispatcher's link surface. The `#[non_exhaustive]`
//! variants enumerate only the modern algorithms Pulsar supports per
//! its compliance posture (NIST SP 800-131A "FIPS-approved hash functions"
//! plus BLAKE2 for non-FIPS deployments).

pub mod aead;
pub mod hash;

pub use aead::{AeadAlgorithm, AeadKey};
pub use hash::{HashAlgorithm, Hasher, blake2b512, blake2s256};
pub use hash::{sha3_256, sha3_384, sha3_512};
pub use hash::{sha256, sha384, sha512};
