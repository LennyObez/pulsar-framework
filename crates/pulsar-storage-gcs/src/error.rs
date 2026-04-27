//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// GCS API call failed.
    #[error("GCS API call failed: {0}")]
    Api(String),

    /// Object not found.
    #[error("GCS object not found: {bucket}/{name}")]
    NotFound {
        /// Google Cloud Storage bucket name.
        bucket: String,
        /// Object name within the bucket.
        name: String,
    },

    /// Retention-policy violation (deletion prevented by retention period).
    #[error("GCS retention violation: {reason}")]
    RetentionViolation {
        /// Free-form reason the operation violated the retention policy (e.g. `retention-period-not-elapsed`, `event-based-hold-active`, `temporary-hold-active`).
        reason: String,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
