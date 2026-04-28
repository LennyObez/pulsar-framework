//! Argon2id password hashing (RFC 9106) — wrapper over RustCrypto's
//! audited [`argon2`] crate.
//!
//! Per Decision 2.60 (multi-source crypto stack), Argon2id is sourced
//! from RustCrypto (audited, not formally verified) rather than HACL\*
//! — HACL\* does not ship Argon2 in its formally-verified surface.
//! Argon2id is the only memory-hard primitive in Pulsar's classical
//! cryptographic surface; the memory-hardness property is what makes
//! it the right choice for password hashing (resistant to GPU/ASIC
//! brute-force attacks per RFC 9106 § 1).
//!
//! # Algorithm choice — Argon2id only
//!
//! RFC 9106 § 1 specifies three Argon2 variants: Argon2d (data-
//! dependent, faster but timing-attack vulnerable), Argon2i (data-
//! independent, slower but timing-attack resistant), and Argon2id
//! (hybrid — data-independent first half, data-dependent second
//! half). RFC 9106 § 4 recommends **Argon2id as the default**: it
//! resists side-channel attacks during the first half pass (where
//! private inputs are transformed into the working memory) and
//! resists time-memory trade-off attacks during the second half. The
//! Pulsar surface exposes only Argon2id.
//!
//! # Parameter recommendations
//!
//! Per OWASP Password Storage Cheat Sheet (2024 update) and RFC 9106
//! § 4 "MEMORY-CONSTRAINED ENVIRONMENTS":
//!
//! - **m_cost**: 19456 KiB (≈ 19 MiB) — OWASP "minimum recommended"
//! - **t_cost**: 2 iterations — OWASP "minimum recommended"
//! - **p_cost**: 1 lane — OWASP "minimum recommended"
//! - **output_len**: 32 bytes — sufficient for 256-bit keys
//!
//! [`Argon2idParams::default`] returns this profile. Higher-resource
//! deployments can construct stronger profiles via
//! [`Argon2idParams::new`] and validate them via
//! [`Argon2idParams::validate`].
//!
//! # Salt requirements
//!
//! RFC 9106 § 3.1 specifies salt length ∈ [8, 2³²−1] bytes; OWASP
//! recommends **at least 16 bytes** of cryptographically random salt
//! for password storage. Pulsar's wrapper enforces the RFC 9106 lower
//! bound (8 bytes) at the type level via the `salt: &[u8]` parameter
//! check; production callers should always supply 16+ bytes from a CSPRNG.
//!
//! Salt generation requires a CSPRNG. Phase 1.1.B.4 deliberately does
//! not bundle an RNG abstraction — callers supply their own salt
//! bytes. A future kernel-wide RNG sub-phase will add salt-generating
//! convenience methods on top of this surface.
//!
//! # Key-material handling
//!
//! - **Password** — `&SecretBox<[u8]>` (borrowed, read once into the
//!   `argon2` crate's input; caller retains ownership for re-use).
//! - **Salt** — `&[u8]` (public per RFC 9106; salts are stored
//!   alongside the hash in the PHC string output).
//! - **Output** — caller-allocated `&mut [u8]` for raw KDF use, or
//!   PHC string for password storage. The PHC string contains the
//!   salt + hash but not the password — RFC 9106-compliant storage
//!   format.

use crate::error::{Error, Result};
use argon2::password_hash::{PasswordHash, PasswordHasher, PasswordVerifier, Salt, SaltString};
use argon2::{Algorithm, Argon2, Params, Version};
use secrecy::{ExposeSecret, SecretBox};

/// Argon2id parameter set. Encapsulates the four tunable cost
/// parameters from RFC 9106 § 3.1.
#[derive(Clone, Copy, Debug, Eq, PartialEq, Hash)]
pub struct Argon2idParams {
    /// Memory cost in KiB. RFC 9106 § 3.1: minimum `8 * p_cost`,
    /// maximum `2³²-1` KiB. OWASP recommends ≥ 19456 (≈ 19 MiB).
    pub m_cost: u32,
    /// Number of iterations. RFC 9106 § 3.1: minimum 1, maximum
    /// `2³²-1`. OWASP recommends ≥ 2.
    pub t_cost: u32,
    /// Parallelism (number of lanes). RFC 9106 § 3.1: minimum 1,
    /// maximum `2²⁴-1`. OWASP recommends ≥ 1.
    pub p_cost: u32,
    /// Output keying material length in bytes. RFC 9106 § 3.1:
    /// minimum 4, maximum `2³²-1`. Pulsar default is 32 (sufficient
    /// for a 256-bit symmetric key).
    pub output_len: usize,
}

impl Default for Argon2idParams {
    /// OWASP Password Storage Cheat Sheet (2024) "minimum recommended"
    /// Argon2id profile: m=19456 KiB, t=2, p=1, output=32 bytes. Suitable
    /// for typical web request password hashing where p99 latency must
    /// stay below ~100 ms on commodity hardware. Higher-stakes contexts
    /// (root credentials, regulator-mandated profiles) should use
    /// stronger parameters via [`Argon2idParams::new`].
    fn default() -> Self {
        Self {
            m_cost: 19_456,
            t_cost: 2,
            p_cost: 1,
            output_len: 32,
        }
    }
}

impl Argon2idParams {
    /// Construct an Argon2id parameter set without validation. Callers
    /// should subsequently invoke [`validate`](Self::validate) before
    /// passing the parameters to [`derive_key`] / [`hash_password`].
    #[must_use]
    pub const fn new(m_cost: u32, t_cost: u32, p_cost: u32, output_len: usize) -> Self {
        Self {
            m_cost,
            t_cost,
            p_cost,
            output_len,
        }
    }

    /// Validate that the parameters fall within the RFC 9106 § 3.1
    /// admissible range and that `m_cost ≥ 8 * p_cost` (the per-lane
    /// memory floor).
    ///
    /// # Errors
    ///
    /// - [`Error::InvalidArgon2Params`] with a `reason` string that
    ///   identifies which bound was violated.
    pub const fn validate(&self) -> Result<()> {
        if self.t_cost < 1 {
            return Err(Error::InvalidArgon2Params {
                reason: "t_cost must be ≥ 1 (RFC 9106 § 3.1)",
            });
        }
        if self.p_cost < 1 {
            return Err(Error::InvalidArgon2Params {
                reason: "p_cost must be ≥ 1 (RFC 9106 § 3.1)",
            });
        }
        if self.p_cost > 0x00FF_FFFF {
            return Err(Error::InvalidArgon2Params {
                reason: "p_cost must be ≤ 2^24 - 1 (RFC 9106 § 3.1)",
            });
        }
        // RFC 9106 § 3.1: m_cost must be at least 8 * p_cost (per-lane
        // memory floor — Argon2 splits memory across lanes and each
        // lane needs at least 8 blocks of working memory).
        if self.m_cost < 8 * self.p_cost {
            return Err(Error::InvalidArgon2Params {
                reason: "m_cost must be ≥ 8 * p_cost (RFC 9106 § 3.1)",
            });
        }
        if self.output_len < 4 {
            return Err(Error::InvalidArgon2Params {
                reason: "output_len must be ≥ 4 bytes (RFC 9106 § 3.1)",
            });
        }
        if self.output_len > u32::MAX as usize {
            return Err(Error::InvalidArgon2Params {
                reason: "output_len must be ≤ 2^32 - 1 bytes (RFC 9106 § 3.1)",
            });
        }
        Ok(())
    }

    /// Build the underlying [`argon2::Params`] from this parameter set.
    /// Callers should not need this directly — [`derive_key`] /
    /// [`hash_password`] / [`verify_password`] consume the typed
    /// `Argon2idParams` and convert internally.
    fn to_argon2_params(self) -> Result<Params> {
        Params::new(self.m_cost, self.t_cost, self.p_cost, Some(self.output_len)).map_err(|_| {
            Error::InvalidArgon2Params {
                reason: "argon2::Params construction rejected the parameter set",
            }
        })
    }
}

/// Construct a configured [`Argon2`] instance from validated
/// parameters. The resulting hasher is bound to Argon2id v0x13 (RFC
/// 9106 v1.3, the only version widely deployed in 2024+).
fn argon2id_with(params: Argon2idParams) -> Result<Argon2<'static>> {
    params.validate()?;
    let argon2_params = params.to_argon2_params()?;
    Ok(Argon2::new(
        Algorithm::Argon2id,
        Version::V0x13,
        argon2_params,
    ))
}

/// Argon2id KDF — derive `output.len()` bytes of output keying
/// material from `password` and `salt` under the given `params`.
///
/// `output.len()` must equal `params.output_len`. Callers using
/// Argon2id as a key-derivation function (e.g., to derive an
/// envelope-encryption key from a passphrase) typically allocate a
/// fixed-size buffer matching the output length up-front.
///
/// `salt` must be at least 8 bytes per RFC 9106 § 3.1; production
/// callers should supply ≥ 16 bytes from a CSPRNG.
///
/// # Errors
///
/// - [`Error::InvalidArgon2Params`] — `params.validate()` failed.
/// - [`Error::InvalidOutputLength`] — `output.len() != params.output_len`.
/// - [`Error::InvalidPhcString`] — only as a defensive catch-all if
///   `argon2` returns an unexpected error from the raw KDF path; not
///   reachable on validated inputs.
pub fn derive_key(
    password: &SecretBox<[u8]>,
    salt: &[u8],
    params: Argon2idParams,
    output: &mut [u8],
) -> Result<()> {
    if output.len() != params.output_len {
        return Err(Error::InvalidOutputLength {
            expected: params.output_len,
            actual: output.len(),
        });
    }
    if salt.len() < 8 {
        return Err(Error::InvalidArgon2Params {
            reason: "salt must be ≥ 8 bytes (RFC 9106 § 3.1)",
        });
    }
    let argon2 = argon2id_with(params)?;
    argon2
        .hash_password_into(password.expose_secret(), salt, output)
        .map_err(|_| Error::InvalidPhcString)
}

/// Argon2id password hashing — produce a PHC-string-encoded hash
/// suitable for direct database storage.
///
/// The output format is the standard PHC string per the password-hash
/// spec, e.g. `$argon2id$v=19$m=19456,t=2,p=1$<salt-b64>$<hash-b64>`.
/// The string is self-contained: it stores the algorithm identifier,
/// version, parameters, salt, and hash so [`verify_password`] can
/// re-run the KDF without out-of-band parameter passing.
///
/// `salt` must be at least 8 bytes per RFC 9106 § 3.1; production
/// callers should supply ≥ 16 bytes from a CSPRNG.
///
/// # Errors
///
/// - [`Error::InvalidArgon2Params`] — `params.validate()` failed, or
///   `salt.len() < 8`.
/// - [`Error::InvalidPhcString`] — `argon2`'s PHC-encoder rejected the
///   salt (e.g., salt encoding produced an invalid base64 length); not
///   reachable on RFC-9106-conformant 8+ byte salts.
pub fn hash_password(
    password: &SecretBox<[u8]>,
    salt: &[u8],
    params: Argon2idParams,
) -> Result<String> {
    if salt.len() < 8 {
        return Err(Error::InvalidArgon2Params {
            reason: "salt must be ≥ 8 bytes (RFC 9106 § 3.1)",
        });
    }
    let argon2 = argon2id_with(params)?;
    let salt_string = SaltString::encode_b64(salt).map_err(|_| Error::InvalidPhcString)?;
    let hash = argon2
        .hash_password(password.expose_secret(), Salt::from(&salt_string))
        .map_err(|_| Error::InvalidPhcString)?;
    Ok(hash.to_string())
}

/// Verify that `password` matches the hash encoded in `phc_string`.
/// The PHC string carries its own algorithm + parameters + salt, so no
/// out-of-band [`Argon2idParams`] is required for verification.
///
/// Comparison is constant-time via [`argon2::PasswordVerifier::verify_password`]
/// (which delegates to `subtle`-backed byte comparison internally).
///
/// # Errors
///
/// - [`Error::InvalidPhcString`] — `phc_string` is malformed, encodes
///   an unsupported algorithm (e.g., raw Argon2d / Argon2i instead of
///   Argon2id), or specifies parameters outside the wrapper's
///   admissible range.
/// - [`Error::PasswordVerifyFailed`] — `phc_string` parses correctly
///   but the recomputed hash does not match the stored one.
pub fn verify_password(password: &SecretBox<[u8]>, phc_string: &str) -> Result<()> {
    let parsed = PasswordHash::new(phc_string).map_err(|_| Error::InvalidPhcString)?;
    // Reject non-Argon2id PHC strings — the wrapper exposes only
    // Argon2id and silently accepting Argon2d / Argon2i would let a
    // misconfigured caller verify against a weaker variant.
    if parsed.algorithm.as_str() != argon2::ARGON2ID_IDENT.as_str() {
        return Err(Error::InvalidPhcString);
    }
    let argon2 = Argon2::default();
    argon2
        .verify_password(password.expose_secret(), &parsed)
        .map_err(|err| match err {
            argon2::password_hash::Error::Password => Error::PasswordVerifyFailed,
            _ => Error::InvalidPhcString,
        })
}
