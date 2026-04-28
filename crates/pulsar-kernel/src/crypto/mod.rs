//! Cryptographic primitive surface — safe Rust wrappers over the HACL\* FFI
//! per Decision 2.53 + 2.60 + ADR-0009.
//!
//! Each sub-module wraps one primitive family with typed inputs/outputs
//! and lifecycle invariants enforced by the type system:
//!
//! - [`hash`]   — SHA-2 (256/384/512), SHA-3 (256/384/512), BLAKE2b/2s
//! - [`aead`]   — AES-128/256-GCM, ChaCha20-Poly1305
//! - [`signature`] — Ed25519 (RFC 8032); ML-DSA-65 hybrid via libcrux (Phase 1.1.C)
//! - [`kem`]    — X25519 (RFC 7748); ML-KEM-768 hybrid via libcrux (Phase 1.1.C)
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
//!
//! # `SecretBox` parameter convention — consume vs borrow
//!
//! Constructors that take a [`secrecy::SecretBox<[u8]>`] follow one of
//! two principled conventions, picked per primitive based on whether
//! the wrapper retains the secret for repeated use or copies it once
//! into FFI-owned state:
//!
//! - **Borrowed (`&SecretBox<[u8]>`)** — used by [`AeadKey::new`]. The
//!   AEAD constructor immediately copies the key into HACL\*'s expanded
//!   state (`EverCrypt_AEAD_create_in`) and discards the input
//!   reference. The caller retains ownership of the original
//!   `SecretBox` so the same key material can be re-used (e.g. to
//!   construct a second [`AeadKey`] with a different algorithm) without
//!   forcing a clone.
//! - **Consumed (`SecretBox<[u8]>` by value)** — used by
//!   [`Ed25519PrivateKey::from_bytes`] and
//!   [`X25519PrivateKey::from_bytes`]. Signature + KEM private-key
//!   wrappers store the seed/scalar inside the wrapper for repeated
//!   `sign` / `diffie_hellman` calls. Consuming the input transfers
//!   the zeroize-on-drop ownership to the wrapper — when the wrapper
//!   is dropped the secret material is wiped exactly once, with no
//!   dangling alias the caller could accidentally clone.
//!
//! Phase 1.1.B.4 (HKDF / HMAC / Argon2id) and Phase 1.1.C (libcrux PQC)
//! follow the same rule: borrow when the FFI immediately copies and
//! discards, consume when the wrapper retains the secret across calls.

pub mod aead;
pub mod hash;
pub mod kem;
pub mod signature;

pub use aead::{AeadAlgorithm, AeadKey};
pub use hash::{HashAlgorithm, Hasher, blake2b512, blake2s256};
pub use hash::{sha3_256, sha3_384, sha3_512};
pub use hash::{sha256, sha384, sha512};
pub use kem::{X25519PrivateKey, X25519PublicKey};
pub use signature::{Ed25519PrivateKey, Ed25519PublicKey, Ed25519Signature};

/// Hex-encode a byte slice with a length-truncated suffix for `Debug`
/// formatting. Avoids dumping full key/signature bytes into log output
/// while still showing enough hex to identify the value.
///
/// Shared between every `crypto::*` module's manual `Debug` impl on
/// public-key / signature types — never invoked on secret material
/// (private-key `Debug` impls use `finish_non_exhaustive()` and never
/// touch the seed bytes).
pub(crate) fn hex_encode_short(bytes: &[u8]) -> String {
    use core::fmt::Write;
    let n = bytes.len().min(8);
    let mut out = String::with_capacity(n * 2 + 16);
    for b in &bytes[..n] {
        let _ = write!(out, "{b:02x}");
    }
    let _ = write!(out, "…({} bytes)", bytes.len());
    out
}
