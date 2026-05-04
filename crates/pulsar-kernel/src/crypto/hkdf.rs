//! HKDF — HMAC-based Key Derivation Function (RFC 5869).
//!
//! Per Decision 2.53 + 2.60 + ADR-0009. Phase 1.1.B.4 ships HKDF over
//! the same HMAC primitives shipped by [`crate::crypto::hmac`]
//! (HMAC-SHA-2-256/384/512 and HMAC-BLAKE2b/2s). HKDF is parameterised
//! by the underlying HMAC choice via [`HmacAlgorithm`] so callers
//! select the same algorithm enum across both surfaces.
//!
//! # Two-step construction
//!
//! HKDF deliberately separates extraction from expansion (RFC 5869 §
//! 2.2, § 2.3):
//!
//! - **Extract** (`HKDF-Extract(salt, IKM) -> PRK`): condenses the
//!   input keying material `IKM` into a fixed-length pseudorandom key
//!   `PRK` of `HashLen` bytes using `HMAC(salt, IKM)`. The salt is a
//!   non-secret optional random value (RFC 5869 § 3.1) — empty salt is
//!   permitted and is treated as `HashLen` zero bytes by HACL\*'s
//!   internal handling.
//! - **Expand** (`HKDF-Expand(PRK, info, L) -> OKM`): expands the PRK
//!   into `L` bytes of output keying material `OKM` deterministically
//!   from the (PRK, info) pair. `info` is optional (may be empty);
//!   `L ≤ 255 * HashLen` per RFC 5869 § 2.3.
//!
//! [`hkdf`] (the all-in-one helper) chains the two steps for callers
//! that don't need to expose an intermediate PRK.
//!
//! # Key-material handling
//!
//! Following the canonical pattern from Phase 1.1.B.2-3:
//!
//! - **IKM** (input keying material) — `&SecretBox<[u8]>` (borrowed,
//!   read once into the FFI; caller retains ownership).
//! - **PRK** (pseudorandom key) — `SecretBox<[u8]>` returned by
//!   [`extract`] (consumed by the caller; either passed to [`expand`]
//!   or dropped via zeroize-on-drop).
//! - **OKM** (output keying material) — `SecretBox<[u8]>` returned by
//!   [`expand`] / [`hkdf`] (consumed by the caller; downstream KDF
//!   chains can call `expose_secret()` without intermediate cleartext
//!   copies).
//! - **Salt** + **info** — public per RFC 5869, accepted as `&[u8]`.
//!
//! # Length bounds
//!
//! - `salt.len()` ≤ `u32::MAX`, `info.len()` ≤ `u32::MAX`,
//!   `ikm.len()` ≤ `u32::MAX`, `prk.len()` ≤ `u32::MAX`. Practical
//!   ceiling well above any plausible KDF input.
//! - `length` ≤ `255 * HashLen` for [`expand`] / [`hkdf`] per RFC 5869
//!   § 2.3 (HKDF's counter-mode expansion fixes the maximum output
//!   length at 255 hash blocks). Excess requests yield
//!   [`Error::InvalidOutputLength`].

#![allow(unsafe_code)]

use crate::crypto::ensure_initialized;
use crate::crypto::hmac::HmacAlgorithm;
use crate::error::{Error, Result};
// Pearlite specification macros — explicit-import pattern from D.2.b
// + D.2.c. Only `trusted` is needed in this module: HKDF returns
// `SecretBox<[u8]>` everywhere which is logic-opaque to Creusot v0.11
// (no View / DeepModel impl on `secrecy::SecretBox`), so the
// per-function postconditions we'd want (PRK / OKM length) cannot be
// expressed without first modelling SecretBox in Pearlite. That
// modelling is deferred to a future sprint; here we apply
// `#[trusted]` at the FFI boundary with documentation citing HACL\*
// F\* verification (ADR-0009).
use creusot_std::macros::trusted;
use pulsar_crypto_hacl_bindings::ffi;
use secrecy::{ExposeSecret, SecretBox};

/// Maximum HKDF output length per RFC 5869 § 2.3 — 255 hash blocks.
///
/// No Pearlite postcondition: this internal helper isn't called from
/// any contract, and a postcondition referring to a cross-module ghost
/// like `crypto::hmac::spec_tag_len` would require re-exporting it,
/// which the `#[logic(open)]` macro doesn't do on stable rustc. The
/// program-side correctness of the 255× multiplication is covered by
/// the existing KAT tests (HKDF max-output rejection at length =
/// 255 × tag_len + 1).
const fn max_output_len(algo: HmacAlgorithm) -> usize {
    255 * algo.tag_len()
}

/// HKDF-Extract — derive a pseudorandom key (PRK) from input keying
/// material (IKM) and an optional non-secret salt.
///
/// `salt` may be empty (RFC 5869 § 3.1 explicitly permits this) — the
/// internal HMAC then runs with a `HashLen`-byte zero key per the spec.
/// `ikm` may also be empty in principle though that is unusual.
///
/// The PRK output is exactly [`HmacAlgorithm::tag_len`] bytes wrapped
/// in [`SecretBox<[u8]>`] for downstream zeroize-on-drop handling.
///
/// # Errors
///
/// - [`Error::InputTooLong`] — `salt.len() > u32::MAX` or
///   `ikm.expose_secret().len() > u32::MAX`. Practically unreachable
///   on 64-bit hosts but the wrapper surfaces the FFI ceiling
///   explicitly for clarity.
///
/// `#[trusted]` because the body crosses the HACL\* FFI boundary
/// (`EverCrypt_HKDF_extract`) and the return type `SecretBox<[u8]>`
/// is logic-opaque to Creusot v0.11 (no View / DeepModel
/// implementation on `secrecy::SecretBox`). Trust anchored in HACL\*
/// F\* verification of HKDF-Extract per ADR-0009; the safe-wrapper
/// layer enforces only the FFI length-bound preconditions. Useful
/// postconditions on the PRK byte content require modelling
/// `SecretBox` in Pearlite which is deferred to a future sprint.
#[trusted]
pub fn extract(algo: HmacAlgorithm, salt: &[u8], ikm: &SecretBox<[u8]>) -> Result<SecretBox<[u8]>> {
    ensure_initialized();

    let salt_len = u32::try_from(salt.len()).map_err(|_| Error::InputTooLong {
        actual: salt.len(),
        max: u32::MAX as usize,
    })?;
    let ikm_bytes = ikm.expose_secret();
    let ikm_len = u32::try_from(ikm_bytes.len()).map_err(|_| Error::InputTooLong {
        actual: ikm_bytes.len(),
        max: u32::MAX as usize,
    })?;

    let mut prk = vec![0_u8; algo.tag_len()];

    // SAFETY: EverCrypt_HKDF_extract preconditions:
    //   - `a` is a valid Spec_Hash_Definitions_hash_alg constant —
    //     guaranteed by `HmacAlgorithm::to_ffi` enumerating only
    //     HMAC-supported hashes (RFC 5869 explicitly supports SHA-2
    //     and BLAKE2 via HACL*).
    //   - `prk` points to `HashLen` writable bytes — `prk` is sized
    //     exactly `algo.tag_len()` above.
    //   - `salt` points to `salt_len` readable bytes; may be a
    //     dangling-but-non-null pointer when salt_len = 0 per HACL*'s
    //     contract.
    //   - `ikm` points to `ikm_len` readable bytes; same dangling-non-
    //     null contract for ikm_len = 0.
    //   - `salt_len` + `ikm_len` fit in u32 — checked above.
    // The casts from `*const u8` to `*mut u8` on the read-only inputs
    // are sound: HACL* documents salt + ikm as read-only.
    unsafe {
        ffi::EverCrypt_HKDF_extract(
            algo.to_ffi(),
            prk.as_mut_ptr(),
            salt.as_ptr().cast_mut(),
            salt_len,
            ikm_bytes.as_ptr().cast_mut(),
            ikm_len,
        );
    }

    Ok(SecretBox::new(prk.into_boxed_slice()))
}

/// HKDF-Expand — expand a pseudorandom key (PRK) into `length` bytes
/// of output keying material (OKM) bound to the optional `info` context
/// string.
///
/// The PRK should be at least [`HmacAlgorithm::tag_len`] bytes per RFC
/// 5869 § 2.3 (the spec recommends `HashLen` bytes; HACL\* accepts
/// shorter inputs but security degrades). `info` may be empty.
///
/// `length` must be at most `255 * HashLen` per RFC 5869 § 2.3 —
/// HKDF's counter-mode expansion uses an 8-bit counter, so the maximum
/// output is bounded.
///
/// # Errors
///
/// - [`Error::InvalidOutputLength`] — `length > 255 * tag_len()` or
///   `length == 0`. Zero-length output is rejected because callers
///   producing zero bytes of key material almost always have a bug.
/// - [`Error::InputTooLong`] — `prk.expose_secret().len() > u32::MAX`,
///   `info.len() > u32::MAX`, or `length > u32::MAX`.
///
/// `#[trusted]` per the same rationale as [`extract`] — FFI body
/// (`EverCrypt_HKDF_expand`) + `SecretBox<[u8]>` return type that
/// Creusot v0.11 cannot model. Trust in HACL\* F\* verification of
/// HKDF-Expand per ADR-0009.
#[trusted]
pub fn expand(
    algo: HmacAlgorithm,
    prk: &SecretBox<[u8]>,
    info: &[u8],
    length: usize,
) -> Result<SecretBox<[u8]>> {
    if length == 0 || length > max_output_len(algo) {
        return Err(Error::InvalidOutputLength {
            expected: max_output_len(algo),
            actual: length,
        });
    }
    ensure_initialized();

    let prk_bytes = prk.expose_secret();
    let prk_len = u32::try_from(prk_bytes.len()).map_err(|_| Error::InputTooLong {
        actual: prk_bytes.len(),
        max: u32::MAX as usize,
    })?;
    let info_len = u32::try_from(info.len()).map_err(|_| Error::InputTooLong {
        actual: info.len(),
        max: u32::MAX as usize,
    })?;
    let okm_len = u32::try_from(length).map_err(|_| Error::InputTooLong {
        actual: length,
        max: u32::MAX as usize,
    })?;

    let mut okm = vec![0_u8; length];

    // SAFETY: EverCrypt_HKDF_expand preconditions:
    //   - `a` is a valid Spec_Hash_Definitions_hash_alg constant.
    //   - `okm` points to `length` writable bytes — `okm` sized exactly
    //     `length` above.
    //   - `prk` points to `prk_len` readable bytes; HACL* requires
    //     prk_len >= HashLen for the security claim but does not enforce
    //     this — production callers should pass a HashLen-sized PRK.
    //   - `info` points to `info_len` readable bytes; dangling-non-null
    //     contract for info_len = 0.
    //   - `len` (= `length`) ≤ 255 * HashLen — checked above.
    //   - All length params fit in u32 — checked above.
    // The casts from `*const u8` to `*mut u8` are sound: HACL* documents
    // prk + info as read-only.
    unsafe {
        ffi::EverCrypt_HKDF_expand(
            algo.to_ffi(),
            okm.as_mut_ptr(),
            prk_bytes.as_ptr().cast_mut(),
            prk_len,
            info.as_ptr().cast_mut(),
            info_len,
            okm_len,
        );
    }

    Ok(SecretBox::new(okm.into_boxed_slice()))
}

/// HKDF — `Extract` then `Expand` in a single call. Equivalent to
/// `expand(extract(salt, ikm), info, length)` with the intermediate
/// PRK dropped (zeroize-on-drop) after the expansion.
///
/// Used by callers that do not need to expose the PRK (e.g., deriving
/// a single OKM from password-derived IKM in one shot).
///
/// # Errors
///
/// Same as [`extract`] and [`expand`] — propagated from the underlying
/// calls.
///
/// `#[trusted]` because both transitive calls (`extract`, `expand`)
/// are themselves trusted; Creusot can't compose their (absent)
/// postconditions into a useful one for this wrapper. Trust posture
/// inherited from the constituent calls per ADR-0009.
#[trusted]
pub fn hkdf(
    algo: HmacAlgorithm,
    salt: &[u8],
    ikm: &SecretBox<[u8]>,
    info: &[u8],
    length: usize,
) -> Result<SecretBox<[u8]>> {
    let prk = extract(algo, salt, ikm)?;
    expand(algo, &prk, info, length)
}
