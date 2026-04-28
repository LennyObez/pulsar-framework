//! Key encapsulation / Diffie-Hellman primitives — safe Rust wrappers
//! over HACL\* via FFI.
//!
//! Per Decision 2.53 + 2.60 + ADR-0009, this module wraps the EverCrypt
//! key-agreement dispatcher. Phase 1.1.B.3 ships X25519 (RFC 7748) —
//! the classical key-agreement primitive Pulsar standardises on. P-256
//! ECDH (FIPS 186-5) lands in a follow-up sub-phase. ML-KEM-768 (the
//! NIST PQC primitive Pulsar uses for hybrid KEM per Decision 2.58)
//! lands in Phase 1.1.C via libcrux per Decision 2.60.
//!
//! # X25519 properties
//!
//! - **Algorithm**: Curve25519 Diffie-Hellman per RFC 7748
//! - **Private key**: 32 bytes (clamped per RFC 7748 § 5)
//! - **Public key**: 32 bytes (u-coordinate of `[k]G` where `k` is the
//!   clamped scalar and `G` is the curve base point)
//! - **Shared secret**: 32 bytes
//! - **Constant-time**: HACL\*'s F\* proofs of constant-time and
//!   secret independence cover the scalar multiplication.
//! - **Public-key validation**: per RFC 7748, X25519 does NOT validate
//!   that the peer public key is "non-small-order" before computing
//!   the DH. HACL\*'s `EverCrypt_Curve25519_ecdh` does explicit
//!   small-order rejection — it returns `false` for the documented
//!   degenerate cases. The wrapper surfaces this as
//!   [`Error::InvalidPublicKey`].
//!
//! # Key-material handling
//!
//! Private keys + shared secrets are wrapped in
//! [`secrecy::SecretBox<[u8]>`] following the canonical pattern
//! established by `crypto::aead::AeadKey::new`. Public keys are NOT
//! wrapped — they are public by definition.
//!
//! Shared secrets are returned wrapped in [`SecretBox<[u8]>`] so the
//! caller's downstream HKDF / KDF derivation can operate on
//! `expose_secret()` output without accidental cleartext copies.

#![allow(unsafe_code)]

use crate::error::{Error, Result};
use pulsar_crypto_hacl_bindings::ffi;
use secrecy::{ExposeSecret, SecretBox};
use std::sync::Once;

/// Ensure EverCrypt's CPU dispatcher is initialised — same `Once`
/// pattern as the rest of `crypto`.
static AUTOCONFIG_INIT: Once = Once::new();

fn ensure_initialized() {
    AUTOCONFIG_INIT.call_once(|| {
        // SAFETY: idempotent C function with no caller-side state.
        unsafe { ffi::EverCrypt_AutoConfig2_init() };
    });
}

/// X25519 private scalar (32 bytes).
pub struct X25519PrivateKey {
    secret: SecretBox<[u8]>,
}

impl core::fmt::Debug for X25519PrivateKey {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("X25519PrivateKey").finish_non_exhaustive()
    }
}

impl X25519PrivateKey {
    /// Private scalar length in bytes (RFC 7748 § 5).
    pub const SCALAR_LEN: usize = 32;
    /// Public point length in bytes (RFC 7748 § 5).
    pub const POINT_LEN: usize = 32;
    /// Shared secret length in bytes (RFC 7748 § 5).
    pub const SHARED_LEN: usize = 32;

    /// Construct a private key from a 32-byte scalar wrapped in
    /// [`SecretBox<[u8]>`]. The scalar is clamped per RFC 7748 § 5
    /// internally by HACL\* before use.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidKeyLength`] — `secret.expose_secret().len() != 32`.
    pub fn from_bytes(secret: SecretBox<[u8]>) -> Result<Self> {
        if secret.expose_secret().len() != Self::SCALAR_LEN {
            return Err(Error::InvalidKeyLength {
                expected: Self::SCALAR_LEN,
                actual: secret.expose_secret().len(),
            });
        }
        Ok(Self { secret })
    }

    /// Derive the public key corresponding to this private scalar.
    /// Computes `[k]G` where `k` is the clamped scalar and `G` is the
    /// Curve25519 base point (u = 9).
    #[must_use]
    pub fn public_key(&self) -> X25519PublicKey {
        ensure_initialized();
        let mut public_bytes = [0_u8; Self::POINT_LEN];

        // SAFETY: EverCrypt_Curve25519_secret_to_public preconditions:
        //   - `pub_` points to 32 writable bytes.
        //   - `priv_` points to 32 readable bytes — length-checked at
        //     construction time.
        unsafe {
            ffi::EverCrypt_Curve25519_secret_to_public(
                public_bytes.as_mut_ptr(),
                self.secret.expose_secret().as_ptr().cast_mut(),
            );
        }

        X25519PublicKey {
            bytes: public_bytes,
        }
    }

    /// Compute the X25519 Diffie-Hellman shared secret with `peer`.
    ///
    /// Returns the 32-byte shared secret wrapped in [`SecretBox<[u8]>`].
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidPublicKey`] — `peer` is one of the documented
    ///   small-order points that yields a zero or near-zero shared
    ///   secret. HACL\*'s `EverCrypt_Curve25519_ecdh` rejects these
    ///   cases per the F\* secret-independence postcondition.
    pub fn diffie_hellman(&self, peer: &X25519PublicKey) -> Result<SecretBox<[u8]>> {
        ensure_initialized();
        let mut shared_buf = vec![0_u8; Self::SHARED_LEN];

        // SAFETY: EverCrypt_Curve25519_ecdh preconditions:
        //   - `shared` points to 32 writable bytes — `shared_buf`.
        //   - `my_priv` points to 32 readable bytes — length-checked.
        //   - `their_pub` points to 32 readable bytes — `peer.bytes`.
        // Returns `bool`: true on success, false on small-order
        // public-key rejection.
        let valid = unsafe {
            ffi::EverCrypt_Curve25519_ecdh(
                shared_buf.as_mut_ptr(),
                self.secret.expose_secret().as_ptr().cast_mut(),
                peer.bytes.as_ptr().cast_mut(),
            )
        };

        if !valid {
            // Zeroize the buffer before dropping (defensive — HACL*
            // may have written partial / leaked-shared-secret bytes
            // before the small-order check fired).
            shared_buf.fill(0);
            return Err(Error::InvalidPublicKey);
        }

        Ok(SecretBox::new(shared_buf.into_boxed_slice()))
    }
}

/// X25519 public point (32 bytes — u-coordinate).
#[derive(Clone, Copy, Eq, PartialEq, Hash)]
pub struct X25519PublicKey {
    bytes: [u8; 32],
}

impl core::fmt::Debug for X25519PublicKey {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("X25519PublicKey")
            .field("bytes", &hex_encode_short(&self.bytes))
            .finish()
    }
}

impl X25519PublicKey {
    /// Construct from a 32-byte u-coordinate buffer. The wrapper does
    /// NOT validate that the bytes form a non-small-order point; the
    /// validation happens at [`X25519PrivateKey::diffie_hellman`] time
    /// via HACL\*'s small-order check.
    pub const fn from_bytes(bytes: [u8; 32]) -> Self {
        Self { bytes }
    }

    /// Return the public key as a 32-byte slice.
    #[must_use]
    pub const fn as_bytes(&self) -> &[u8; 32] {
        &self.bytes
    }
}

/// Hex-encode a byte slice with a length-truncated suffix for `Debug`
/// formatting. Avoids dumping full key bytes into log output.
fn hex_encode_short(bytes: &[u8]) -> String {
    use core::fmt::Write;
    let n = bytes.len().min(8);
    let mut out = String::with_capacity(n * 2 + 16);
    for b in &bytes[..n] {
        let _ = write!(out, "{b:02x}");
    }
    let _ = write!(out, "…({} bytes)", bytes.len());
    out
}
