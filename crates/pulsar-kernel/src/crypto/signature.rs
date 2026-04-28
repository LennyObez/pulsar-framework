//! Digital signature primitives — safe Rust wrappers over HACL\* via FFI.
//!
//! Per Decision 2.53 + 2.60 + ADR-0009, this module wraps the EverCrypt
//! signature dispatcher with typed inputs/outputs. Phase 1.1.B.3 ships
//! Ed25519 (RFC 8032) — the signature primitive Pulsar standardises
//! on for the regulated-domain compliance surface. P-256 ECDSA
//! (FIPS 186-5) lands in a follow-up sub-phase together with the
//! deterministic-nonce machinery (RFC 6979).
//!
//! Ed25519 properties:
//!
//! - **Algorithm**: Edwards25519 with Ed25519 signature scheme (RFC 8032)
//! - **Private key**: 32 bytes (the "seed"; expanded internally to a
//!   64-byte scalar + prefix per RFC 8032 § 5.1.5)
//! - **Public key**: 32 bytes (compressed Edwards point)
//! - **Signature**: 64 bytes (R || s, where R is a compressed point and
//!   s is a scalar)
//! - **Deterministic**: Ed25519 is fully deterministic — signing the
//!   same message with the same key always produces the same signature.
//!   No nonce reuse risk, no RFC-6979-style derandomisation needed.
//! - **Constant-time**: signing and verification are constant-time per
//!   HACL\*'s F\* secret-independence proofs.
//!
//! # Key-material handling
//!
//! Private keys are wrapped in [`secrecy::SecretBox<[u8]>`] following
//! the canonical pattern established by `crypto::aead::AeadKey::new`.
//! Caller-side zeroize-on-drop applies to the input bytes; the FFI
//! internally derives expanded scalar + nonce-prefix material per
//! signing operation (no persistent state stored Rust-side).

#![allow(unsafe_code)]

use crate::crypto::{ensure_initialized, hex_encode_short};
use crate::error::{Error, Result};
use pulsar_crypto_hacl_bindings::ffi;
use secrecy::{ExposeSecret, SecretBox};

/// Ed25519 private signing key (32-byte seed) per RFC 8032.
///
/// Constructed from a [`SecretBox<[u8]>`] of exactly 32 bytes; the
/// wrapper enforces zeroize-on-drop on the seed material via the
/// `secrecy` crate's `ZeroizeOnDrop` impl.
pub struct Ed25519PrivateKey {
    seed: SecretBox<[u8]>,
}

impl core::fmt::Debug for Ed25519PrivateKey {
    /// Print only the algorithm name — never expose seed bytes via
    /// `Debug` formatting.
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("Ed25519PrivateKey").finish_non_exhaustive()
    }
}

impl Ed25519PrivateKey {
    /// Private-key seed length in bytes (RFC 8032 § 5.1.5).
    pub const SEED_LEN: usize = 32;
    /// Public-key length in bytes (RFC 8032 § 5.1.5).
    pub const PUBLIC_KEY_LEN: usize = 32;
    /// Signature length in bytes (RFC 8032 § 5.1.6).
    pub const SIGNATURE_LEN: usize = 64;

    /// Construct a private key from a 32-byte seed wrapped in
    /// [`SecretBox<[u8]>`].
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidKeyLength`] — `seed.expose_secret().len() != 32`.
    pub fn from_bytes(seed: SecretBox<[u8]>) -> Result<Self> {
        if seed.expose_secret().len() != Self::SEED_LEN {
            return Err(Error::InvalidKeyLength {
                expected: Self::SEED_LEN,
                actual: seed.expose_secret().len(),
            });
        }
        Ok(Self { seed })
    }

    /// Generate a fresh Ed25519 private key using the OS CSPRNG.
    ///
    /// Draws 32 bytes via [`crate::crypto::rng::try_random_into`] and
    /// wraps them in [`SecretBox<[u8]>`]. The seed is hashed via
    /// SHA-512 internally on first use to derive the actual signing
    /// scalar (RFC 8032 § 5.1.5), so any 32-byte random input is
    /// acceptable.
    ///
    /// # Errors
    ///
    /// - [`Error::RngFailure`] — the OS CSPRNG returned an error.
    pub fn try_generate() -> Result<Self> {
        let bytes = crate::crypto::rng::try_random_bytes(Self::SEED_LEN)?;
        Self::from_bytes(SecretBox::new(bytes.into_boxed_slice()))
    }

    /// Derive the public key corresponding to this private seed.
    /// Computes the elliptic-curve scalar multiplication
    /// `[s]B` where `s` is the SHA-512-derived scalar from the seed
    /// and `B` is the Edwards25519 base point.
    #[must_use]
    pub fn public_key(&self) -> Ed25519PublicKey {
        ensure_initialized();
        let mut public_bytes = [0_u8; Self::PUBLIC_KEY_LEN];

        // SAFETY: EverCrypt_Ed25519_secret_to_public preconditions:
        //   - `public_key` points to 32 writable bytes — `public_bytes`
        //     is a freshly-zeroed 32-byte stack array.
        //   - `private_key` points to 32 readable bytes — checked at
        //     construction time (SEED_LEN = 32).
        // The cast from `*const u8` to `*mut u8` is sound: HACL*
        // documents the seed as read-only.
        unsafe {
            ffi::EverCrypt_Ed25519_secret_to_public(
                public_bytes.as_mut_ptr(),
                self.seed.expose_secret().as_ptr().cast_mut(),
            );
        }

        Ed25519PublicKey {
            bytes: public_bytes,
        }
    }

    /// Sign `message` with this private key, returning a 64-byte
    /// signature. Ed25519 signatures are deterministic per RFC 8032 §
    /// 5.1.6 — signing the same message with the same key always
    /// produces the same output.
    ///
    /// # Errors
    ///
    /// - [`Error::InputTooLong`] — `message.len() > u32::MAX`. RFC 8032
    ///   has no inherent length limit but HACL\*'s API takes a u32
    ///   length; 4 GiB exceeds any plausible signed payload.
    pub fn sign(&self, message: &[u8]) -> Result<Ed25519Signature> {
        ensure_initialized();
        let msg_len = u32::try_from(message.len()).map_err(|_| Error::InputTooLong {
            actual: message.len(),
            max: u32::MAX as usize,
        })?;
        let mut signature = [0_u8; Self::SIGNATURE_LEN];

        // SAFETY: EverCrypt_Ed25519_sign preconditions:
        //   - `signature` points to 64 writable bytes — `signature` is
        //     a freshly-zeroed 64-byte stack array.
        //   - `private_key` points to 32 readable bytes — checked at
        //     construction time.
        //   - `msg` points to `msg_len` readable bytes (may be empty
        //     per RFC 8032; HACL* permits dangling-non-null when
        //     msg_len = 0).
        //   - `msg_len` fits in u32 — checked above.
        unsafe {
            ffi::EverCrypt_Ed25519_sign(
                signature.as_mut_ptr(),
                self.seed.expose_secret().as_ptr().cast_mut(),
                msg_len,
                message.as_ptr().cast_mut(),
            );
        }

        Ok(Ed25519Signature { bytes: signature })
    }
}

/// Ed25519 public verifying key (32 bytes — compressed Edwards point).
#[derive(Clone, Copy, Eq, PartialEq, Hash)]
pub struct Ed25519PublicKey {
    bytes: [u8; 32],
}

impl core::fmt::Debug for Ed25519PublicKey {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("Ed25519PublicKey")
            .field("bytes", &hex_encode_short(&self.bytes))
            .finish()
    }
}

impl Ed25519PublicKey {
    /// Construct from a 32-byte slice. The wrapper does NOT validate
    /// that the bytes form a valid Edwards point; HACL\*'s
    /// `_Ed25519_verify` performs that check at verification time
    /// (returning `false` for invalid points).
    pub const fn from_bytes(bytes: [u8; 32]) -> Self {
        Self { bytes }
    }

    /// Return the public key as a 32-byte slice.
    #[must_use]
    pub const fn as_bytes(&self) -> &[u8; 32] {
        &self.bytes
    }

    /// Verify `signature` over `message` under this public key.
    /// Returns `Ok(())` if the signature is valid;
    /// [`Error::SignatureVerifyFailed`] otherwise (no information
    /// leaked about which step of verification failed — HACL\*'s
    /// constant-time invariant covers the success / failure paths
    /// uniformly).
    ///
    /// # Errors
    ///
    /// - [`Error::SignatureVerifyFailed`] — the signature is not valid
    ///   for the (public key, message) pair, or the public key is not
    ///   on the Edwards curve.
    /// - [`Error::InputTooLong`] — `message.len() > u32::MAX`.
    pub fn verify(&self, message: &[u8], signature: &Ed25519Signature) -> Result<()> {
        ensure_initialized();
        let msg_len = u32::try_from(message.len()).map_err(|_| Error::InputTooLong {
            actual: message.len(),
            max: u32::MAX as usize,
        })?;

        // SAFETY: EverCrypt_Ed25519_verify preconditions:
        //   - `public_key` points to 32 readable bytes — `self.bytes`.
        //   - `msg` points to `msg_len` readable bytes.
        //   - `signature` points to 64 readable bytes — `signature.bytes`.
        //   - All length params fit in u32 — checked above.
        // Returns `bool`: true iff the signature verifies.
        let valid = unsafe {
            ffi::EverCrypt_Ed25519_verify(
                self.bytes.as_ptr().cast_mut(),
                msg_len,
                message.as_ptr().cast_mut(),
                signature.bytes.as_ptr().cast_mut(),
            )
        };

        if valid {
            Ok(())
        } else {
            Err(Error::SignatureVerifyFailed)
        }
    }
}

/// Ed25519 signature (64 bytes — R || s).
#[derive(Clone, Copy, Eq, PartialEq, Hash)]
pub struct Ed25519Signature {
    bytes: [u8; 64],
}

impl core::fmt::Debug for Ed25519Signature {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("Ed25519Signature")
            .field("bytes", &hex_encode_short(&self.bytes))
            .finish()
    }
}

impl Ed25519Signature {
    /// Construct from a 64-byte signature buffer.
    pub const fn from_bytes(bytes: [u8; 64]) -> Self {
        Self { bytes }
    }

    /// Return the signature as a 64-byte slice.
    #[must_use]
    pub const fn as_bytes(&self) -> &[u8; 64] {
        &self.bytes
    }
}
