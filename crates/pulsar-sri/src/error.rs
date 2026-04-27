//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// SRI verification failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Computed digest does not match the expected `integrity` attribute value.
    #[error("SRI digest mismatch (expected {expected}, computed {computed})")]
    DigestMismatch { expected: String, computed: String },

    /// Algorithm prefix (`sha256-` / `sha384-` / `sha512-`) is malformed or unsupported.
    #[error("SRI algorithm prefix invalid or unsupported: {0}")]
    InvalidAlgorithm(String),

    /// Asset URL referenced by template not found in the build-time manifest.
    #[error("SRI manifest entry missing for asset: {0}")]
    ManifestMiss(String),
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
