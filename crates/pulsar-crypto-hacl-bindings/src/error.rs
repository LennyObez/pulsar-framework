//! FFI-side error type per plan Section XVII.7 error strategy.
//!
//! HACL* primitives return integer status codes; this enum maps them to
//! a typed Rust error. The safe wrappers in `pulsar-kernel::crypto`
//! convert these to `pulsar_kernel::Error` for downstream consumption.

use thiserror::Error;

/// HACL* FFI status conversion errors.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// AEAD authentication tag verification failed (ciphertext tampered or wrong key).
    #[error("HACL* AEAD authentication failed")]
    AeadAuthFailed,

    /// Signature verification failed.
    #[error("HACL* signature verification failed")]
    SignatureVerifyFailed,

    /// Public key or signature is malformed (wrong length, invalid encoding).
    #[error("HACL* malformed input")]
    MalformedInput,

    /// HACL* C library returned an unrecognised status code (should never happen).
    #[error("HACL* unknown status code: {0}")]
    UnknownStatus(i32),
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
