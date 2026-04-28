//! Authenticated encryption with associated data (AEAD) — safe Rust
//! wrappers over HACL\* via FFI.
//!
//! Per Decision 2.53 + 2.60 + ADR-0009, this module wraps the EverCrypt
//! agile AEAD dispatcher with typed inputs/outputs. Three primitives:
//!
//! - **AES-128-GCM** (FIPS 197 + SP 800-38D) — 16-byte key, 12-byte nonce, 16-byte tag.
//! - **AES-256-GCM** (FIPS 197 + SP 800-38D) — 32-byte key, 12-byte nonce, 16-byte tag.
//! - **ChaCha20-Poly1305** (RFC 8439) — 32-byte key, 12-byte nonce, 16-byte tag.
//!
//! All three share the same lifecycle: [`AeadKey::new`] allocates the
//! EverCrypt state (which holds expanded round keys / per-key
//! pre-computation), [`AeadKey::encrypt`] / [`AeadKey::decrypt`] perform
//! the operation, and [`Drop`] frees the FFI state via
//! `EverCrypt_AEAD_free`. Multiple encrypt/decrypt calls on the same
//! `AeadKey` share the expanded state — no per-call key expansion cost.
//!
//! # Output format
//!
//! Encrypt returns `ciphertext || tag` packed in a single `Vec<u8>`.
//! Decrypt expects the same packed format on input. Tag length is
//! always 16 bytes for the three supported algorithms; ciphertext
//! length equals plaintext length per the AEAD construction.
//!
//! # Nonce safety (CRITICAL)
//!
//! **AEAD primitives are catastrophically insecure under nonce reuse**:
//! encrypting two different plaintexts with the same `(key, nonce)`
//! pair leaks plaintext XOR (GCM, ChaCha20) and lets an attacker forge
//! arbitrary ciphertexts (GCM authentication key recovery). Callers
//! MUST ensure each `(key, nonce)` pair is used for at most one
//! encrypt operation. Sprint 1.6 capability tokens + Sprint 2.5
//! session adapters wire up the nonce-uniqueness invariants for
//! protocol-level use; this module enforces only length checks.
//!
//! Nonce length is fixed at **12 bytes** for all three algorithms.
//! ChaCha20-Poly1305 mandates 12 bytes per RFC 8439; AES-GCM permits
//! other lengths but 12 bytes is the FIPS-recommended length per NIST
//! SP 800-38D § 5.2.1.1. Pulsar enforces 12 bytes uniformly so callers
//! can write algorithm-agnostic code.

// `unsafe_code` is allowed only in this module (the FFI boundary). Each
// `unsafe` block carries a SAFETY comment documenting its pre/post-
// conditions against the upstream HACL* contract.
#![allow(unsafe_code)]

use crate::error::{Error, Result};
use core::ptr::{self, NonNull};
use pulsar_crypto_hacl_bindings::ffi;
use std::sync::Once;

/// Initialise EverCrypt's runtime CPU-feature dispatcher exactly once
/// per process. Same `Once` instance pattern as `crypto::hash` — the
/// underlying `EverCrypt_AutoConfig2_init` is idempotent at the C
/// layer; `Once` avoids redundant atomic synchronisation on the hot
/// path. Each crypto sub-module owns its own `Once` rather than
/// sharing a crate-level singleton because the AEAD path is
/// independent from the hash path (different EverCrypt sub-modules).
static AUTOCONFIG_INIT: Once = Once::new();

fn ensure_initialized() {
    AUTOCONFIG_INIT.call_once(|| {
        // SAFETY: `EverCrypt_AutoConfig2_init` takes no arguments,
        // returns void, and has no caller-side state to clean up. HACL\*
        // documents it as safe to call multiple times — `Once` enforces
        // the "exactly once" semantic without runtime cost on subsequent
        // AEAD invocations.
        unsafe { ffi::EverCrypt_AutoConfig2_init() };
    });
}

/// Authenticated-encryption algorithms supported by `pulsar-kernel::crypto`.
///
/// The variant set is `#[non_exhaustive]`; future sprints can add
/// AES-CCM or other AEAD constructions without breaking downstream
/// `match` exhaustiveness assumptions.
///
/// AES-128-CCM, AES-256-CCM, AES-128-CCM8, AES-256-CCM8 are exposed by
/// the underlying HACL\* dispatcher (per `Spec_Agile_AEAD.h`) but
/// deliberately excluded from this enum. Pulsar standardises on GCM
/// and ChaCha20-Poly1305 for the regulated-domain compliance surface
/// (NIST SP 800-38D and RFC 8439); CCM variants land only if a specific
/// regulatory framework requires them.
#[non_exhaustive]
#[derive(Debug, Copy, Clone, Eq, PartialEq, Hash)]
pub enum AeadAlgorithm {
    /// AES-128-GCM per FIPS 197 + NIST SP 800-38D. 16-byte key, 12-byte
    /// nonce, 16-byte tag. Hardware-accelerated on x86-64 via AES-NI +
    /// PCLMULQDQ when available; portable C fallback otherwise.
    Aes128Gcm,
    /// AES-256-GCM per FIPS 197 + NIST SP 800-38D. 32-byte key, 12-byte
    /// nonce, 16-byte tag. Recommended for FIPS 140-3 modules.
    Aes256Gcm,
    /// ChaCha20-Poly1305 per RFC 8439. 32-byte key, 12-byte nonce,
    /// 16-byte tag. Software-friendly (no AES-NI dependency); preferred
    /// on platforms without dedicated AES instructions.
    ChaCha20Poly1305,
}

impl AeadAlgorithm {
    /// Key length in bytes for this algorithm.
    #[must_use]
    pub const fn key_len(self) -> usize {
        match self {
            Self::Aes128Gcm => 16,
            Self::Aes256Gcm | Self::ChaCha20Poly1305 => 32,
        }
    }

    /// Nonce / IV length in bytes. Fixed at 12 for all three supported
    /// algorithms (RFC 8439 mandate for ChaCha20-Poly1305; FIPS-
    /// recommended length per NIST SP 800-38D § 5.2.1.1 for AES-GCM).
    #[must_use]
    pub const fn nonce_len(self) -> usize {
        12
    }

    /// Authentication tag length in bytes. Fixed at 16 for all three
    /// supported algorithms.
    #[must_use]
    pub const fn tag_len(self) -> usize {
        16
    }

    /// Map to the HACL\* / EverCrypt algorithm-id byte.
    ///
    /// Reference: `crates/pulsar-crypto-hacl-bindings/hacl-c/include/Hacl_Spec.h`:
    /// ```c
    /// #define Spec_Agile_AEAD_AES128_GCM        0
    /// #define Spec_Agile_AEAD_AES256_GCM        1
    /// #define Spec_Agile_AEAD_CHACHA20_POLY1305 2
    /// ```
    /// Returns the bindgen `Spec_Agile_AEAD_alg` typedef alias rather
    /// than raw `u8` so a future change to the underlying typedef
    /// propagates as a Rust-level type mismatch.
    const fn to_ffi(self) -> ffi::Spec_Agile_AEAD_alg {
        match self {
            Self::Aes128Gcm => 0,
            Self::Aes256Gcm => 1,
            Self::ChaCha20Poly1305 => 2,
        }
    }
}

/// AEAD key handle holding the EverCrypt state allocated by
/// `EverCrypt_AEAD_create_in`.
///
/// The state contains algorithm-specific pre-computation (expanded
/// round keys for AES-GCM, key for ChaCha20-Poly1305). Multiple
/// `encrypt`/`decrypt` calls on the same `AeadKey` reuse the expanded
/// state — no per-call cost. Freed via `EverCrypt_AEAD_free` in `Drop`.
///
/// **Key material zeroization**: HACL\*'s `EverCrypt_AEAD_free` is
/// expected to zeroize the expanded state internally per F\* secret-
/// independence proofs. The original key bytes passed to
/// [`AeadKey::new`] are NOT held by `AeadKey` — caller is responsible
/// for zeroizing the input slice (e.g., via `secrecy::Secret` +
/// `zeroize`).
pub struct AeadKey {
    algo: AeadAlgorithm,
    state: NonNull<ffi::EverCrypt_AEAD_state_s>,
}

// SAFETY: AeadKey owns its FFI state pointer exclusively. No internal
// shared mutability; encrypt/decrypt take `&self` (HACL*'s state is
// read-only after creation — the expanded round keys do not change
// across encrypt/decrypt calls), so concurrent calls on the same
// instance are sound. Sync would require the FFI state to be
// thread-safe under concurrent access; HACL* documentation does not
// guarantee this for the AEAD state, so Sync is NOT impl'd. Send is
// fine because ownership transfer is single-threaded by definition.
unsafe impl Send for AeadKey {}

impl core::fmt::Debug for AeadKey {
    /// Print only the algorithm — the FFI state pointer is uninteresting
    /// to debug consumers and would expose internal layout details that
    /// belong on the audit boundary, not in formatted output.
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("AeadKey")
            .field("algorithm", &self.algo)
            .finish_non_exhaustive()
    }
}

impl AeadKey {
    /// Construct an AEAD key from raw key bytes.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidKeyLength`] — `key.len() != algo.key_len()`.
    /// - [`Error::StateAllocationFailed`] — HACL\*'s `_create_in` returned
    ///   `EverCrypt_Error_Success` but the resulting state pointer was
    ///   NULL (out-of-memory at the C layer).
    /// - Other [`Error`] variants if EverCrypt returns a non-success
    ///   status code (e.g., `UnsupportedAlgorithm` if the algorithm-id
    ///   mapping drifts — should be unreachable given the closed match).
    pub fn new(algo: AeadAlgorithm, key: &[u8]) -> Result<Self> {
        if key.len() != algo.key_len() {
            return Err(Error::InvalidKeyLength {
                expected: algo.key_len(),
                actual: key.len(),
            });
        }

        ensure_initialized();

        let mut state_ptr: *mut ffi::EverCrypt_AEAD_state_s = ptr::null_mut();

        // SAFETY: EverCrypt_AEAD_create_in preconditions:
        //   - `a` is a valid Spec_Agile_AEAD_alg constant — guaranteed
        //     by AeadAlgorithm::to_ffi enumerating only supported algos.
        //   - `dst` is a valid pointer to a writable `*mut state_s`
        //     location — `&mut state_ptr` is a stack-allocated mut ref.
        //   - `k` points to at least `algo.key_len()` readable bytes —
        //     checked above against key.len().
        // The cast from `*const u8` to `*mut u8` is sound: HACL*
        // documents the key as read-only despite the C signature
        // lacking `const`.
        let rc = unsafe {
            ffi::EverCrypt_AEAD_create_in(
                algo.to_ffi(),
                &raw mut state_ptr,
                key.as_ptr().cast_mut(),
            )
        };

        if rc != 0 {
            // EverCrypt_Error codes from EverCrypt_Error.h:
            //   0 = Success, 1 = UnsupportedAlgorithm, 2 = InvalidKey,
            //   3 = AuthenticationFailure, 4 = InvalidIVLength,
            //   5 = DecodeError, 6 = MaximumLengthExceeded.
            // For `_create_in`, only Success + UnsupportedAlgorithm are
            // documented as possible. Both should be unreachable here:
            // - UnsupportedAlgorithm requires an out-of-range alg byte,
            //   blocked by AeadAlgorithm::to_ffi's closed match.
            // Map to a typed binding-crate error for diagnostic logging.
            return Err(Error::StateAllocationFailed);
        }

        let state = NonNull::new(state_ptr).ok_or(Error::StateAllocationFailed)?;
        Ok(Self { algo, state })
    }

    /// Algorithm this key was constructed for.
    #[must_use]
    pub const fn algorithm(&self) -> AeadAlgorithm {
        self.algo
    }

    /// Encrypt `plaintext` with associated data `aad` under `nonce`,
    /// producing `ciphertext || tag` packed in a single `Vec<u8>`.
    ///
    /// Returned vector length is `plaintext.len() + algo.tag_len()`.
    /// Pass the output verbatim to [`AeadKey::decrypt`] for round-trip.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidNonceLength`] — `nonce.len() != algo.nonce_len()`.
    /// - [`Error::InputTooLong`] — any of `nonce`, `aad`, `plaintext`
    ///   exceeds `u32::MAX` bytes (HACL\*'s API takes 32-bit lengths).
    pub fn encrypt(&self, nonce: &[u8], aad: &[u8], plaintext: &[u8]) -> Result<Vec<u8>> {
        if nonce.len() != self.algo.nonce_len() {
            return Err(Error::InvalidNonceLength {
                expected: self.algo.nonce_len(),
                actual: nonce.len(),
            });
        }

        let nonce_len = u32_len(nonce.len())?;
        let aad_len = u32_len(aad.len())?;
        let plain_len = u32_len(plaintext.len())?;

        let mut output = vec![0_u8; plaintext.len() + self.algo.tag_len()];
        let (cipher_buf, tag_buf) = output.split_at_mut(plaintext.len());

        // SAFETY: EverCrypt_AEAD_encrypt preconditions:
        //   - `s` is a valid AeadKey state (held in NonNull until Drop).
        //   - `iv` points to `iv_len` readable bytes — checked above.
        //   - `ad` points to `ad_len` readable bytes (may be empty).
        //   - `plain` points to `plain_len` readable bytes.
        //   - `cipher` points to at least `plain_len` writable bytes —
        //     `cipher_buf` is the prefix of `output` of exact length.
        //   - `tag` points to 16 writable bytes — `tag_buf` is the
        //     16-byte suffix of `output`.
        //   - All length params fit in u32 — checked via u32_len.
        // Casts from `*const u8` to `*mut u8` are sound: HACL* documents
        // iv/ad/plain as read-only despite the C signature lacking `const`.
        let rc = unsafe {
            ffi::EverCrypt_AEAD_encrypt(
                self.state.as_ptr(),
                nonce.as_ptr().cast_mut(),
                nonce_len,
                aad.as_ptr().cast_mut(),
                aad_len,
                plaintext.as_ptr().cast_mut(),
                plain_len,
                cipher_buf.as_mut_ptr(),
                tag_buf.as_mut_ptr(),
            )
        };

        if rc != 0 {
            // For `EverCrypt_AEAD_encrypt`, only Success + InvalidKey
            // are documented as possible. InvalidKey occurs iff `s` is
            // NULL — blocked by NonNull above. Should be unreachable;
            // map to a generic FFI error for diagnostics.
            return Err(Error::Hacl {
                source: pulsar_crypto_hacl_bindings::error::Error::UnknownStatus(i32::from(rc)),
            });
        }

        Ok(output)
    }

    /// Decrypt `ciphertext_with_tag` (the concatenated output of
    /// [`AeadKey::encrypt`]) under `nonce` with associated data `aad`,
    /// returning the recovered plaintext.
    ///
    /// Verifies the authentication tag in constant time relative to
    /// the tag-comparison step (per HACL\*'s F\* constant-time proofs).
    /// On authentication failure, returns
    /// [`Error::AeadAuthFailed`] with no information about which byte
    /// of the tag mismatched.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidNonceLength`] — `nonce.len() != algo.nonce_len()`.
    /// - [`Error::InvalidOutputLength`] — `ciphertext_with_tag.len()` is
    ///   shorter than `algo.tag_len()` (the tag does not fit).
    /// - [`Error::AeadAuthFailed`] — authentication tag verification failed.
    /// - [`Error::InputTooLong`] — any input exceeds `u32::MAX` bytes.
    pub fn decrypt(&self, nonce: &[u8], aad: &[u8], ciphertext_with_tag: &[u8]) -> Result<Vec<u8>> {
        if nonce.len() != self.algo.nonce_len() {
            return Err(Error::InvalidNonceLength {
                expected: self.algo.nonce_len(),
                actual: nonce.len(),
            });
        }

        let tag_len = self.algo.tag_len();
        if ciphertext_with_tag.len() < tag_len {
            return Err(Error::InvalidOutputLength {
                expected: tag_len,
                actual: ciphertext_with_tag.len(),
            });
        }

        let cipher_len_bytes = ciphertext_with_tag.len() - tag_len;
        let (cipher_part, tag_part) = ciphertext_with_tag.split_at(cipher_len_bytes);

        let nonce_len = u32_len(nonce.len())?;
        let aad_len = u32_len(aad.len())?;
        let cipher_len = u32_len(cipher_len_bytes)?;

        let mut plaintext = vec![0_u8; cipher_len_bytes];

        // SAFETY: EverCrypt_AEAD_decrypt preconditions:
        //   - `s` is a valid AeadKey state.
        //   - `iv` points to `iv_len` readable bytes.
        //   - `ad` points to `ad_len` readable bytes.
        //   - `cipher` points to `cipher_len` readable bytes.
        //   - `tag` points to 16 readable bytes — `tag_part` is the
        //     16-byte suffix of `ciphertext_with_tag` (length checked).
        //   - `dst` points to at least `cipher_len` writable bytes —
        //     `plaintext` is freshly allocated to that exact length.
        // On authentication failure, HACL* zeroes the dst buffer per
        // F* postcondition (no plaintext leak on tag mismatch).
        let rc = unsafe {
            ffi::EverCrypt_AEAD_decrypt(
                self.state.as_ptr(),
                nonce.as_ptr().cast_mut(),
                nonce_len,
                aad.as_ptr().cast_mut(),
                aad_len,
                cipher_part.as_ptr().cast_mut(),
                cipher_len,
                tag_part.as_ptr().cast_mut(),
                plaintext.as_mut_ptr(),
            )
        };

        match rc {
            // EverCrypt_Error_Success
            0 => Ok(plaintext),
            // EverCrypt_Error_AuthenticationFailure — most common failure
            // mode; ciphertext / aad / tag tampered, or wrong key.
            3 => Err(Error::AeadAuthFailed),
            // EverCrypt_Error_InvalidIVLength — should be unreachable
            // (we enforce 12-byte nonce above), but map for completeness.
            4 => Err(Error::InvalidNonceLength {
                expected: self.algo.nonce_len(),
                actual: nonce.len(),
            }),
            // Any other status (InvalidKey if s is NULL, etc.) —
            // unreachable but mapped as a defensive default.
            other => Err(Error::Hacl {
                source: pulsar_crypto_hacl_bindings::error::Error::UnknownStatus(i32::from(other)),
            }),
        }
    }
}

impl Drop for AeadKey {
    fn drop(&mut self) {
        // SAFETY: `state` is valid (held in NonNull throughout the
        // AeadKey lifetime). EverCrypt_AEAD_free is the matching
        // deallocator for EverCrypt_AEAD_create_in. HACL* zeroes the
        // expanded key state internally before freeing per F* secret-
        // independence postcondition.
        unsafe { ffi::EverCrypt_AEAD_free(self.state.as_ptr()) };
    }
}

/// Convert a `usize` length to `u32`, returning [`Error::InputTooLong`]
/// on overflow. Used at the FFI boundary because HACL\*'s AEAD APIs
/// accept 32-bit length parameters; 4 GiB exceeds any plausible Pulsar
/// payload size.
fn u32_len(len: usize) -> Result<u32> {
    u32::try_from(len).map_err(|_| Error::InputTooLong {
        actual: len,
        max: u32::MAX as usize,
    })
}
