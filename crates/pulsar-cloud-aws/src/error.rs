//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// AWS API call failed (network, throttling, region misconfiguration).
    #[error("AWS API call failed: {0}")]
    Api(String),

    /// AWS credential resolution failed (no valid credential found in chain).
    #[error("AWS credential resolution failed")]
    CredentialResolution,

    /// IAM permission denied.
    #[error("AWS permission denied: {action}")]
    PermissionDenied {
        /// IAM action identifier the caller attempted (e.g. `s3:GetObject`, `kms:Decrypt`, `secretsmanager:GetSecretValue`).
        action: String,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
