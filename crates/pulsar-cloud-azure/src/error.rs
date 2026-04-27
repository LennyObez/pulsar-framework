//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Azure API call failed.
    #[error("Azure API call failed: {0}")]
    Api(String),

    /// Managed Identity / Service Principal credential resolution failed.
    #[error("Azure credential resolution failed")]
    CredentialResolution,

    /// Azure RBAC permission denied.
    #[error("Azure permission denied: {action}")]
    PermissionDenied {
        /// Azure RBAC action the caller attempted (e.g. `Microsoft.Storage/storageAccounts/blobServices/containers/blobs/read`, `Microsoft.KeyVault/vaults/secrets/getSecret/action`).
        action: String,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
