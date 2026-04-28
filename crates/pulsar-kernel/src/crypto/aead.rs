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
//! # Output formats
//!
//! Encrypt has two output shapes:
//!
//! - [`AeadKey::encrypt`] returns `ciphertext || tag` packed in a
//!   single `Vec<u8>`. Convenient for one-shot use.
//! - [`AeadKey::encrypt_into`] writes to a caller-provided `&mut [u8]`
//!   buffer (must be `plaintext.len() + tag_len()` long). Avoids the
//!   heap allocation on hot paths.
//!
//! Decrypt has two input shapes:
//!
//! - [`AeadKey::decrypt`] takes `ciphertext_with_tag` (the verbatim
//!   output of [`AeadKey::encrypt`]).
//! - [`AeadKey::decrypt_separate`] takes `ciphertext` and `tag` as
//!   distinct slices. Common in protocols (TLS 1.3, Noise, MLS) that
//!   carry the two parts in separate fields.
//!
//! Tag length is always 16 bytes for the three supported algorithms;
//! ciphertext length equals plaintext length per the AEAD construction.
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
//!
//! # Key-material handling
//!
//! [`AeadKey::new`] takes the key wrapped in [`secrecy::SecretBox<[u8]>`]
//! (also known as [`secrecy::SecretSlice<u8>`]) — callers must construct
//! the secret wrapper before invoking the constructor. The wrapper:
//!
//! - Zeroizes the key bytes when the [`SecretBox`] is dropped (per the
//!   `secrecy` crate's `ZeroizeOnDrop` impl).
//! - Forces explicit `expose_secret()` access at the FFI call site,
//!   making accidental clones / logging visible at code-review time.
//!
//! Once `AeadKey::new` returns, the key bytes are passed once into
//! `EverCrypt_AEAD_create_in` which copies them into the FFI state's
//! expanded key buffer. The original `SecretBox` continues to own the
//! input bytes; the caller can drop it after `new` returns and the
//! zeroize-on-drop will fire. The expanded state inside the FFI is
//! NOT zeroized by `EverCrypt_AEAD_free` (HACL\* upstream limitation —
//! see [`AeadKey`] Drop SAFETY comment). Sensitive deployments that
//! need expanded-state zeroization should track the upstream HACL\*
//! issue or fork the binding crate to add explicit zeroization.

// `unsafe_code` is allowed only in this module (the FFI boundary). Each
// `unsafe` block carries a SAFETY comment documenting its pre/post-
// conditions against the upstream HACL* contract.
#![allow(unsafe_code)]

use crate::error::{Error, Result};
use core::ptr::{self, NonNull};
use pulsar_crypto_hacl_bindings::ffi;
use secrecy::{ExposeSecret, SecretBox};
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
/// The state contains algorithm-specific pre-computation: expanded
/// round keys for AES-GCM (the round-key portion is read-only across
/// encrypt/decrypt calls), the key for ChaCha20-Poly1305, plus a
/// per-state scratch region used as transient working memory during
/// each encrypt/decrypt call. Multiple `encrypt`/`decrypt` calls on the
/// same `AeadKey` reuse the expanded state — no per-call key expansion
/// cost. Freed via `EverCrypt_AEAD_free` in `Drop`.
///
/// **Expanded-state zeroization** — `EverCrypt_AEAD_free` (per the
/// HACL\* C source `crates/pulsar-crypto-hacl-bindings/hacl-c/src/EverCrypt_AEAD.c`)
/// frees the heap-allocated expanded-key buffer via `KRML_HOST_FREE`
/// (i.e., `free(3)`) **without zeroizing it first**. Sensitive
/// deployments that require zero-on-free for the expanded round keys
/// must track upstream HACL\* hardening or fork the binding crate to
/// add an explicit zeroization step. Pulsar's mitigation: keep the
/// expanded state alive only as long as needed (drop the `AeadKey`
/// promptly on session end); the original key bytes passed to
/// [`AeadKey::new`] are owned by the caller's [`SecretBox`] and ARE
/// zeroized on drop per the `secrecy` crate contract.
pub struct AeadKey {
    algo: AeadAlgorithm,
    state: NonNull<ffi::EverCrypt_AEAD_state_s>,
}

// SAFETY: `AeadKey` owns its FFI state pointer exclusively (single-owner
// lifecycle — `new` constructs, `Drop` frees, no aliasing). Send is
// sound because ownership transfer to another thread is single-threaded
// by definition (Rust move semantics).
//
// `Sync` is INTENTIONALLY NOT impl'd. The HACL* AEAD state contains a
// scratch region (per `crates/pulsar-crypto-hacl-bindings/hacl-c/src/EverCrypt_AEAD.c`,
// the `(*s).ek` buffer used as `scratch_b` at offset 304U / 368U
// during AES-GCM encrypt/decrypt) that is mutated during each
// operation. Concurrent calls from multiple threads to `&self.encrypt(...)`
// or `&self.decrypt(...)` would race on this scratch region —
// catastrophic for correctness even though the round keys themselves
// are read-only. `!Sync` prevents this at the type system level: a
// shared `&AeadKey` cannot be observed simultaneously from multiple
// threads. The expanded round keys and per-key pre-computation
// (immutable parts of the state) ARE safe to read concurrently, but
// the scratch region's mutability rules out a blanket Sync impl.
//
// Within a single thread, multiple `&self` borrows can coexist (per
// Rust's borrow rules) and call encrypt/decrypt sequentially — the
// scratch region is reused but never aliased because each call
// completes before the next.
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
    /// Construct an AEAD key from a [`SecretBox`]-wrapped key byte
    /// slice.
    ///
    /// The [`SecretBox<[u8]>`] wrapper enforces zeroize-on-drop on the
    /// caller's input bytes via the `secrecy` crate's
    /// `ZeroizeOnDrop` impl, and forces explicit `expose_secret()`
    /// access at the FFI call site. Pulsar establishes this as the
    /// canonical pattern across all crypto primitives that consume key
    /// material; subsequent sprint phases (1.1.B.3 Ed25519/X25519
    /// private keys, 1.1.B.4 HKDF/HMAC keys, Argon2id passwords) wrap
    /// their key inputs the same way for consistency.
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
    pub fn new(algo: AeadAlgorithm, key: &SecretBox<[u8]>) -> Result<Self> {
        let key_bytes: &[u8] = key.expose_secret();
        if key_bytes.len() != algo.key_len() {
            return Err(Error::InvalidKeyLength {
                expected: algo.key_len(),
                actual: key_bytes.len(),
            });
        }

        ensure_initialized();

        let mut state_ptr: *mut ffi::EverCrypt_AEAD_state_s = ptr::null_mut();

        // SAFETY: EverCrypt_AEAD_create_in preconditions:
        //   - `a` is a valid Spec_Agile_AEAD_alg constant — guaranteed
        //     by AeadAlgorithm::to_ffi enumerating only supported algos.
        //   - `dst` is a valid pointer to a writable `*mut state_s`
        //     location — `&raw mut state_ptr` is a stack-allocated mut
        //     raw pointer.
        //   - `k` points to at least `algo.key_len()` readable bytes —
        //     checked above against key_bytes.len().
        // The cast from `*const u8` to `*mut u8` is sound: HACL*
        // documents the key as read-only despite the C signature
        // lacking `const`.
        let rc = unsafe {
            ffi::EverCrypt_AEAD_create_in(
                algo.to_ffi(),
                &raw mut state_ptr,
                key_bytes.as_ptr().cast_mut(),
            )
        };

        if rc != 0 {
            // EverCrypt_Error codes from EverCrypt_Error.h:
            //   0 = Success, 1 = UnsupportedAlgorithm, 2 = InvalidKey,
            //   3 = AuthenticationFailure, 4 = InvalidIVLength,
            //   5 = DecodeError, 6 = MaximumLengthExceeded.
            // For `_create_in`, only Success + UnsupportedAlgorithm are
            // documented as possible. Both should be unreachable here
            // (UnsupportedAlgorithm requires an out-of-range alg byte,
            // blocked by AeadAlgorithm::to_ffi's closed match). Map to
            // a defensive default for diagnostic logging.
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
    /// Pass the output verbatim to [`AeadKey::decrypt`] for round-trip,
    /// or split into ciphertext + tag at offset `plaintext.len()` for
    /// [`AeadKey::decrypt_separate`].
    ///
    /// For hot paths that need to avoid the heap allocation, use
    /// [`AeadKey::encrypt_into`] which writes directly into a caller-
    /// provided buffer.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidNonceLength`] — `nonce.len() != algo.nonce_len()`.
    /// - [`Error::InputTooLong`] — any of `nonce`, `aad`, `plaintext`
    ///   exceeds `u32::MAX` bytes (HACL\*'s API takes 32-bit lengths).
    pub fn encrypt(&self, nonce: &[u8], aad: &[u8], plaintext: &[u8]) -> Result<Vec<u8>> {
        let mut output = vec![0_u8; plaintext.len() + self.algo.tag_len()];
        self.encrypt_into(nonce, aad, plaintext, &mut output)?;
        Ok(output)
    }

    /// Encrypt `plaintext` into a caller-provided `output` buffer.
    ///
    /// `output` must have length exactly `plaintext.len() +
    /// algo.tag_len()`; on success the buffer holds `ciphertext || tag`
    /// in place. Useful for high-throughput call sites where the
    /// per-call heap allocation in [`AeadKey::encrypt`] dominates the
    /// AEAD primitive cost (audit-chain entry encryption, per-message
    /// session encryption, etc.).
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidNonceLength`] — `nonce.len() != algo.nonce_len()`.
    /// - [`Error::InvalidOutputLength`] — `output.len() != plaintext.len() + tag_len()`.
    /// - [`Error::InputTooLong`] — input exceeds u32::MAX bytes.
    pub fn encrypt_into(
        &self,
        nonce: &[u8],
        aad: &[u8],
        plaintext: &[u8],
        output: &mut [u8],
    ) -> Result<()> {
        let expected_output_len = plaintext.len() + self.algo.tag_len();
        if output.len() != expected_output_len {
            return Err(Error::InvalidOutputLength {
                expected: expected_output_len,
                actual: output.len(),
            });
        }
        if nonce.len() != self.algo.nonce_len() {
            return Err(Error::InvalidNonceLength {
                expected: self.algo.nonce_len(),
                actual: nonce.len(),
            });
        }

        let nonce_len = u32_len(nonce.len())?;
        let aad_len = u32_len(aad.len())?;
        let plain_len = u32_len(plaintext.len())?;

        let (cipher_buf, tag_buf) = output.split_at_mut(plaintext.len());
        debug_assert_eq!(tag_buf.len(), self.algo.tag_len());

        // SAFETY: EverCrypt_AEAD_encrypt preconditions:
        //   - `s` is a valid AeadKey state (held in NonNull until Drop).
        //   - `iv` points to `iv_len` readable bytes — checked above.
        //   - `ad` points to `ad_len` readable bytes (may be empty).
        //   - `plain` points to `plain_len` readable bytes.
        //   - `cipher` points to at least `plain_len` writable bytes —
        //     `cipher_buf` is the prefix of `output` of exact length.
        //   - `tag` points to 16 writable bytes — `tag_buf` is the
        //     16-byte suffix of `output` (split point + length checked).
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
            // NULL — blocked by NonNull. Should be unreachable; map to
            // a generic FFI error for diagnostics.
            return Err(Error::Hacl {
                source: pulsar_crypto_hacl_bindings::error::Error::UnknownStatus(i32::from(rc)),
            });
        }

        Ok(())
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
    /// Use [`AeadKey::decrypt_separate`] when ciphertext and tag arrive
    /// in distinct slices (TLS 1.3 record layer, Noise transport
    /// messages, MLS application messages, etc.).
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidNonceLength`] — `nonce.len() != algo.nonce_len()`.
    /// - [`Error::InvalidOutputLength`] — `ciphertext_with_tag.len()` is
    ///   shorter than `algo.tag_len()` (the tag does not fit).
    /// - [`Error::AeadAuthFailed`] — authentication tag verification failed.
    /// - [`Error::InputTooLong`] — any input exceeds `u32::MAX` bytes.
    pub fn decrypt(&self, nonce: &[u8], aad: &[u8], ciphertext_with_tag: &[u8]) -> Result<Vec<u8>> {
        let tag_len = self.algo.tag_len();
        if ciphertext_with_tag.len() < tag_len {
            return Err(Error::InvalidOutputLength {
                expected: tag_len,
                actual: ciphertext_with_tag.len(),
            });
        }
        let cipher_len_bytes = ciphertext_with_tag.len() - tag_len;
        let (cipher_part, tag_part) = ciphertext_with_tag.split_at(cipher_len_bytes);
        self.decrypt_separate(nonce, aad, cipher_part, tag_part)
    }

    /// Decrypt `ciphertext` and verify `tag` under `nonce` with
    /// associated data `aad`, returning the recovered plaintext.
    ///
    /// The packed `ciphertext_with_tag` form is convenient for one-shot
    /// in-memory use; the separate form matches protocols that carry
    /// ciphertext and tag in distinct fields (TLS 1.3 record layer,
    /// Noise / MLS transport messages).
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidNonceLength`] — `nonce.len() != algo.nonce_len()`.
    /// - [`Error::InvalidOutputLength`] — `tag.len() != algo.tag_len()`.
    /// - [`Error::AeadAuthFailed`] — authentication tag verification failed.
    /// - [`Error::InputTooLong`] — any input exceeds `u32::MAX` bytes.
    pub fn decrypt_separate(
        &self,
        nonce: &[u8],
        aad: &[u8],
        ciphertext: &[u8],
        tag: &[u8],
    ) -> Result<Vec<u8>> {
        if nonce.len() != self.algo.nonce_len() {
            return Err(Error::InvalidNonceLength {
                expected: self.algo.nonce_len(),
                actual: nonce.len(),
            });
        }
        if tag.len() != self.algo.tag_len() {
            return Err(Error::InvalidOutputLength {
                expected: self.algo.tag_len(),
                actual: tag.len(),
            });
        }

        let nonce_len = u32_len(nonce.len())?;
        let aad_len = u32_len(aad.len())?;
        let cipher_len = u32_len(ciphertext.len())?;

        let mut plaintext = vec![0_u8; ciphertext.len()];

        // SAFETY: EverCrypt_AEAD_decrypt preconditions:
        //   - `s` is a valid AeadKey state.
        //   - `iv` points to `iv_len` readable bytes.
        //   - `ad` points to `ad_len` readable bytes.
        //   - `cipher` points to `cipher_len` readable bytes.
        //   - `tag` points to 16 readable bytes — checked above against
        //     algo.tag_len().
        //   - `dst` points to at least `cipher_len` writable bytes —
        //     `plaintext` is freshly allocated to that exact length.
        // On authentication failure, HACL* leaves the dst buffer in an
        // unspecified state per the C function's contract. The wrapper
        // returns Err(AeadAuthFailed) so the caller never observes the
        // buffer; the Vec drops without exposure. Defensive note:
        // future versions of pulsar-kernel should explicitly zeroize
        // `plaintext` on the auth-failure path before dropping it, in
        // case some compiler optimisation leaves a residual copy
        // somewhere — tracked for the Phase 1.1.D Creusot-contracts pass.
        let rc = unsafe {
            ffi::EverCrypt_AEAD_decrypt(
                self.state.as_ptr(),
                nonce.as_ptr().cast_mut(),
                nonce_len,
                aad.as_ptr().cast_mut(),
                aad_len,
                ciphertext.as_ptr().cast_mut(),
                cipher_len,
                tag.as_ptr().cast_mut(),
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
        // AeadKey lifetime). `EverCrypt_AEAD_free` is the matching
        // deallocator for `EverCrypt_AEAD_create_in`. NOTE: Per the
        // HACL* C source `crates/pulsar-crypto-hacl-bindings/hacl-c/src/EverCrypt_AEAD.c`,
        // the free function calls `KRML_HOST_FREE` (i.e., `free(3)`)
        // on the expanded-key buffer + state struct WITHOUT explicit
        // zeroization. The expanded round keys / pre-computation for
        // ChaCha20 may be recoverable from heap memory until the OS
        // recycles the pages. Sensitive deployments should track
        // upstream HACL* hardening to add a `memset_s` / `explicit_bzero`
        // call in `EverCrypt_AEAD_free`. The wrapper's mitigation is to
        // hold AeadKey instances no longer than necessary so the heap
        // window of exposure is short.
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
