//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Azure Blob API call failed.
    #[error("Azure Blob API call failed: {0}")]
    Api(String),

    /// Blob not found.
    #[error("Azure Blob not found: {container}/{blob}")]
    NotFound {
        /// Azure Blob Storage container name.
        container: String,
        /// Blob name within the container.
        blob: String,
    },

    /// Immutability policy violation (legal hold or time-based retention).
    #[error("Azure Blob immutability violation: {reason}")]
    ImmutabilityViolation {
        /// Free-form reason the operation violated the immutability policy (e.g. `legal-hold`, `time-based-retention`, `version-level-immutability`).
        reason: String,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
