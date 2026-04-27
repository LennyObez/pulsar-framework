//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// GCP API call failed.
    #[error("GCP API call failed: {0}")]
    Api(String),

    /// Application Default Credentials / Workload Identity resolution failed.
    #[error("GCP credential resolution failed")]
    CredentialResolution,

    /// GCP IAM permission denied.
    #[error("GCP permission denied: {action}")]
    PermissionDenied {
        /// GCP IAM permission the caller attempted (e.g. `storage.objects.get`, `cloudkms.cryptoKeyVersions.useToDecrypt`).
        action: String,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
