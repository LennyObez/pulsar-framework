//! Crate-level error type per plan Section XVII.7 error strategy.
//!
//! Every fallible kernel operation returns [`Result<T>`] where the [`Error`]
//! enum is the single source of truth for failure modes. The variants are
//! `#[non_exhaustive]` so future sprints can add variants without breaking
//! downstream `match` exhaustiveness assumptions.

use thiserror::Error;

/// Kernel-level error type per plan Section XVII.7.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Cryptographic input length exceeds the protocol-specified maximum.
    /// Currently emitted at the FFI boundary when input length overflows
    /// `u32` (HACL\*'s incremental hash + AEAD APIs accept `u32` lengths;
    /// 4 GiB is the practical ceiling, well above any plausible Pulsar
    /// payload).
    #[error("input length {actual} exceeds maximum {max}")]
    InputTooLong {
        /// The actual input length that caused the overflow.
        actual: usize,
        /// The maximum length accepted by the underlying primitive.
        max: usize,
    },

    /// Hash input cumulative length exceeded the algorithm's per-message
    /// limit. EverCrypt's incremental hash returns
    /// `EverCrypt_Error_MaximumLengthExceeded` when the sum of all
    /// `update` chunk lengths since `init` exceeds the algorithm-specific
    /// ceiling: 2^61 − 1 bytes for SHA-2-256, 2^64 − 1 bytes for SHA-2-
    /// 384/512 + SHA-3 + BLAKE2. The limits are practically unreachable
    /// (2^61 bytes ≈ 2 EiB) but the variant exists for completeness of
    /// the FFI status-code mapping.
    #[error("hash input cumulative length exceeded the algorithm's per-message limit")]
    HashInputLimitExceeded,

    /// Cryptographic key length does not match the algorithm's expected length.
    #[error("invalid key length: expected {expected}, got {actual}")]
    InvalidKeyLength {
        /// Algorithm-required key length in bytes.
        expected: usize,
        /// Length supplied by the caller.
        actual: usize,
    },

    /// Cryptographic nonce / IV length does not match the algorithm's expected length.
    #[error("invalid nonce length: expected {expected}, got {actual}")]
    InvalidNonceLength {
        /// Algorithm-required nonce length in bytes.
        expected: usize,
        /// Length supplied by the caller.
        actual: usize,
    },

    /// Output buffer length does not match the algorithm's expected length.
    /// Emitted when caller-allocated buffers are sized incorrectly.
    #[error("invalid output buffer length: expected {expected}, got {actual}")]
    InvalidOutputLength {
        /// Algorithm-required output length in bytes.
        expected: usize,
        /// Length supplied by the caller.
        actual: usize,
    },

    /// AEAD authentication tag verification failed — ciphertext was tampered
    /// with or the wrong key was used. Returned in constant time relative to
    /// the tag-comparison step (per HACL\*'s constant-time invariant).
    #[error("AEAD authentication failed (tag mismatch or wrong key)")]
    AeadAuthFailed,

    /// HMAC verification failed — message was tampered with or the wrong
    /// key was used. The wrapper performs constant-time tag comparison
    /// (`subtle::ConstantTimeEq`) so the failure path leaks no timing
    /// information about which byte position differed.
    #[error("MAC verification failed (tag mismatch or wrong key)")]
    MacVerifyFailed,

    /// Digital-signature verification failed.
    #[error("signature verification failed")]
    SignatureVerifyFailed,

    /// Argon2id password verification failed — supplied password did
    /// not match the stored hash. Returned in constant time relative to
    /// the comparison step (`argon2::PasswordVerifier::verify_password`
    /// performs constant-time comparison internally).
    #[error("Argon2id password verification failed")]
    PasswordVerifyFailed,

    /// Argon2id parameters fall outside the RFC 9106 § 3.1 admissible
    /// range. Specific bounds are documented on
    /// [`crate::crypto::argon2::Argon2idParams::validate`].
    #[error("Argon2id parameters out of range: {reason}")]
    InvalidArgon2Params {
        /// Human-readable reason summarising which bound was violated.
        reason: &'static str,
    },

    /// Argon2id PHC string format is malformed or references unsupported
    /// algorithm/version parameters. Wrapped from
    /// [`argon2::password_hash::Error`] without preserving the upstream
    /// variant — Pulsar's surface treats every parse failure uniformly.
    #[error("Argon2id PHC string is malformed or unsupported")]
    InvalidPhcString,

    /// Public key fails the on-curve / format / range validation step
    /// required before key-material use (per FIPS 203/204 + RFC 7748 +
    /// RFC 8032 validation requirements).
    #[error("public key validation failed (off-curve, malformed, or out of range)")]
    InvalidPublicKey,

    /// HACL\* / EverCrypt FFI surface returned a non-success status code
    /// that doesn't map to a more specific variant above. The wrapped
    /// [`pulsar_crypto_hacl_bindings::error::Error`] preserves the
    /// underlying status for diagnostic logging.
    #[error("HACL* FFI returned an error: {source}")]
    Hacl {
        /// The underlying FFI error code from the binding crate.
        #[from]
        source: pulsar_crypto_hacl_bindings::error::Error,
    },

    /// EverCrypt failed to allocate state (returned NULL from the
    /// `*_malloc` constructor). Out-of-memory at the C layer.
    #[error("EverCrypt state allocation failed")]
    StateAllocationFailed,

    /// Cryptographically-secure random-byte generation failed. The
    /// kernel surfaces this when the OS-level entropy source
    /// (`getrandom(2)` on Linux, `BCryptGenRandom` on Windows,
    /// `SecRandomCopyBytes` on macOS, etc.) returns an error. In
    /// practice this only fires on very early boot, exhausted entropy
    /// pools on minimal kernels, or sandboxed environments where the
    /// system call is blocked. Production code paths should treat this
    /// as a fatal-but-transient condition and abort the in-flight
    /// operation rather than retrying with reduced entropy.
    #[error("CSPRNG entropy source failed")]
    RngFailure,
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
