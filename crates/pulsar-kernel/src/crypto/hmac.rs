//! HMAC primitive — safe Rust wrappers over HACL\* via FFI.
//!
//! Per Decision 2.53 + 2.60 + ADR-0009. Phase 1.1.B.4 ships keyed
//! message authentication via HMAC (RFC 2104, FIPS 198-1). The wrapper
//! calls `EverCrypt_HMAC_compute` for each authentication; the per-call
//! key handling is single-shot (no expanded-state pre-computation). For
//! protocols that authenticate many messages with the same key, the
//! wrapper retains the [`secrecy::SecretBox<[u8]>`] key inside the
//! [`HmacKey`] for repeated [`HmacKey::compute`] calls without
//! requiring the caller to re-supply key material.
//!
//! # Supported algorithms
//!
//! Per HACL\*'s `EverCrypt_HMAC_is_supported_alg`:
//!
//! - **HMAC-SHA-2-256 / 384 / 512** — RFC 4231, FIPS 198-1
//! - **HMAC-BLAKE2b / BLAKE2s** — RFC 7693
//!
//! HMAC-SHA-1 + HMAC-MD5 are deliberately absent from
//! [`HmacAlgorithm`] per Pulsar's compliance posture (NIST SP 800-131A
//! deprecates SHA-1 HMAC for new applications). HMAC-SHA-3 is not
//! exposed by HACL\*'s EverCrypt dispatcher in this vendoring tier and
//! is not part of Pulsar's first-pass kernel surface.
//!
//! # Constant-time guarantees
//!
//! The HACL\* HMAC implementation is constant-time relative to message
//! contents per the F\* secret-independence proofs (input length is
//! treated as public). Tag comparison in [`HmacKey::verify`] uses
//! [`subtle::ConstantTimeEq`] so the verification path leaks no timing
//! information about which byte position differed.
//!
//! # Key-material handling
//!
//! HMAC accepts keys of any length (RFC 2104 § 2: longer than block
//! size are hashed; shorter are zero-padded internally by HACL\*).
//! Pulsar enforces only a `u32::MAX` upper bound at construction time
//! to prevent FFI length-parameter overflow. Production callers should
//! supply at least `tag_len()` bytes of cryptographically random key
//! material to avoid the security degradation that comes with
//! shorter-than-output keys.
//!
//! Keys are wrapped in [`SecretBox<[u8]>`] following the canonical
//! pattern from Phase 1.1.B.3 — the wrapper consumes the input
//! [`SecretBox`] and retains it for repeated `compute` calls so the
//! zeroize-on-drop ownership transfers exactly once.

#![allow(unsafe_code)]

use crate::error::{Error, Result};
use pulsar_crypto_hacl_bindings::ffi;
use secrecy::{ExposeSecret, SecretBox};
use std::sync::Once;
use subtle::ConstantTimeEq;

/// Ensure EverCrypt's CPU dispatcher is initialised — same `Once`
/// pattern as the rest of `crypto`.
static AUTOCONFIG_INIT: Once = Once::new();

fn ensure_initialized() {
    AUTOCONFIG_INIT.call_once(|| {
        // SAFETY: idempotent C function with no caller-side state to
        // clean up. HACL* documents the call as safe to invoke multiple
        // times; the `Once` wrapper enforces single-call semantics
        // anyway.
        unsafe { ffi::EverCrypt_AutoConfig2_init() };
    });
}

/// HMAC algorithm identifier.
///
/// The `#[non_exhaustive]` enum scope is strictly the modern hashes
/// Pulsar supports per its compliance posture. Adding variants in
/// future sub-phases stays a non-breaking change for downstream `match`
/// exhaustiveness.
#[derive(Clone, Copy, Eq, PartialEq, Hash, Debug)]
#[non_exhaustive]
pub enum HmacAlgorithm {
    /// HMAC-SHA-2-256 (RFC 4231 + FIPS 198-1) — 32-byte tag.
    Sha256,
    /// HMAC-SHA-2-384 (RFC 4231 + FIPS 198-1) — 48-byte tag.
    Sha384,
    /// HMAC-SHA-2-512 (RFC 4231 + FIPS 198-1) — 64-byte tag.
    Sha512,
    /// HMAC-BLAKE2b (RFC 7693) — 64-byte tag.
    Blake2b512,
    /// HMAC-BLAKE2s (RFC 7693) — 32-byte tag.
    Blake2s256,
}

impl HmacAlgorithm {
    /// HMAC tag length in bytes for this algorithm. Equal to the
    /// underlying hash's digest length (HMAC outputs a tag the same
    /// size as the hash output per RFC 2104 § 2).
    #[must_use]
    pub const fn tag_len(self) -> usize {
        match self {
            Self::Sha256 | Self::Blake2s256 => 32,
            Self::Sha384 => 48,
            Self::Sha512 | Self::Blake2b512 => 64,
        }
    }

    /// Map to the HACL\* / EverCrypt algorithm-id byte.
    ///
    /// Returns the bindgen-generated `Spec_Hash_Definitions_hash_alg`
    /// type alias. Mirrors [`crate::crypto::hash::HashAlgorithm::to_ffi`]
    /// so the same identifier values flow through HMAC and the bare
    /// hash dispatcher.
    const fn to_ffi(self) -> ffi::Spec_Hash_Definitions_hash_alg {
        match self {
            Self::Sha256 => 1,
            Self::Sha384 => 2,
            Self::Sha512 => 3,
            Self::Blake2s256 => 6,
            Self::Blake2b512 => 7,
        }
    }
}

/// HMAC keyed-message-authentication key, parameterised over the
/// algorithm. Wraps a [`SecretBox<[u8]>`] holding the key bytes.
///
/// The wrapper consumes the input [`SecretBox`] and retains it for
/// repeated [`compute`](Self::compute) / [`verify`](Self::verify) calls
/// — the zeroize-on-drop ownership transfers exactly once when the
/// `HmacKey` itself is dropped.
pub struct HmacKey {
    algo: HmacAlgorithm,
    key: SecretBox<[u8]>,
    /// Cached `u32` view of `key.expose_secret().len()`. Computed once
    /// at construction so the FFI calls don't re-validate length on
    /// every `compute` invocation.
    key_len_u32: u32,
}

impl core::fmt::Debug for HmacKey {
    /// Print only the algorithm — never the key bytes nor the length
    /// (length alone is a weak side-channel about key origin).
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("HmacKey")
            .field("algorithm", &self.algo)
            .finish_non_exhaustive()
    }
}

impl HmacKey {
    /// Construct an HMAC key from a [`SecretBox<[u8]>`] of any length
    /// up to `u32::MAX`. RFC 2104 places no lower bound on key length
    /// but security degrades below `tag_len()` bytes of entropy;
    /// production callers should supply at least `tag_len()` bytes of
    /// cryptographically random material.
    ///
    /// # Errors
    ///
    /// - [`Error::InputTooLong`] — `key.expose_secret().len() > u32::MAX`.
    pub fn new(algo: HmacAlgorithm, key: SecretBox<[u8]>) -> Result<Self> {
        let key_len_u32 =
            u32::try_from(key.expose_secret().len()).map_err(|_| Error::InputTooLong {
                actual: key.expose_secret().len(),
                max: u32::MAX as usize,
            })?;
        Ok(Self {
            algo,
            key,
            key_len_u32,
        })
    }

    /// Return the algorithm associated with this key.
    #[must_use]
    pub const fn algorithm(&self) -> HmacAlgorithm {
        self.algo
    }

    /// Compute the HMAC tag over `data`. Returns a fresh `Vec<u8>` of
    /// length [`HmacAlgorithm::tag_len`]. The tag is not secret per the
    /// HMAC threat model — only the key is — so callers may store /
    /// transmit the returned tag without further wrapping.
    ///
    /// # Errors
    ///
    /// - [`Error::InputTooLong`] — `data.len() > u32::MAX`.
    pub fn compute(&self, data: &[u8]) -> Result<Vec<u8>> {
        let mut tag = vec![0_u8; self.algo.tag_len()];
        self.compute_into(data, &mut tag)?;
        Ok(tag)
    }

    /// Compute the HMAC tag over `data` directly into a caller-provided
    /// buffer. The buffer must be at least [`HmacAlgorithm::tag_len`]
    /// bytes long; only that prefix is written.
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidOutputLength`] — `output.len() < tag_len()`.
    /// - [`Error::InputTooLong`] — `data.len() > u32::MAX`.
    pub fn compute_into(&self, data: &[u8], output: &mut [u8]) -> Result<()> {
        if output.len() < self.algo.tag_len() {
            return Err(Error::InvalidOutputLength {
                expected: self.algo.tag_len(),
                actual: output.len(),
            });
        }
        ensure_initialized();

        let data_len = u32::try_from(data.len()).map_err(|_| Error::InputTooLong {
            actual: data.len(),
            max: u32::MAX as usize,
        })?;

        // SAFETY: EverCrypt_HMAC_compute preconditions:
        //   - `a` is a valid Spec_Hash_Definitions_hash_alg constant —
        //     guaranteed by HmacAlgorithm::to_ffi enumerating only
        //     HMAC-supported hashes. (HACL*'s
        //     EverCrypt_HMAC_is_supported_alg returns true for all five.)
        //   - `mac` points to at least `tag_len()` writable bytes —
        //     checked above against `output.len()`.
        //   - `key` points to `key_len` readable bytes — the underlying
        //     `SecretBox<[u8]>` owns a contiguous allocation of exactly
        //     that many bytes.
        //   - `data` points to `data_len` readable bytes; may be a
        //     dangling-but-non-null pointer when data_len = 0 per
        //     HACL*'s contract; HACL* does not dereference when len = 0.
        //   - `key_len` + `data_len` fit in u32 — checked at
        //     construction time and above respectively.
        // The casts from `*const u8` to `*mut u8` are sound: HACL*
        // documents the key + data buffers as read-only despite the C
        // signatures lacking `const`.
        unsafe {
            ffi::EverCrypt_HMAC_compute(
                self.algo.to_ffi(),
                output.as_mut_ptr(),
                self.key.expose_secret().as_ptr().cast_mut(),
                self.key_len_u32,
                data.as_ptr().cast_mut(),
                data_len,
            );
        }
        Ok(())
    }

    /// Verify that `expected_tag` is the HMAC tag over `data` under
    /// this key. Performs constant-time tag comparison via
    /// [`subtle::ConstantTimeEq`] — the verification path leaks no
    /// timing information about which byte position differed (per the
    /// HMAC threat model where a timing oracle on the comparison would
    /// permit per-byte tag forgery).
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidOutputLength`] — `expected_tag.len() != tag_len()`.
    ///   The wrapper rejects mismatched-length tags eagerly so callers
    ///   cannot accidentally accept truncated tags.
    /// - [`Error::InputTooLong`] — `data.len() > u32::MAX`.
    /// - [`Error::MacVerifyFailed`] — the recomputed tag does not match
    ///   `expected_tag` byte-for-byte.
    pub fn verify(&self, data: &[u8], expected_tag: &[u8]) -> Result<()> {
        if expected_tag.len() != self.algo.tag_len() {
            return Err(Error::InvalidOutputLength {
                expected: self.algo.tag_len(),
                actual: expected_tag.len(),
            });
        }
        let computed = self.compute(data)?;
        if computed.ct_eq(expected_tag).into() {
            Ok(())
        } else {
            Err(Error::MacVerifyFailed)
        }
    }
}
