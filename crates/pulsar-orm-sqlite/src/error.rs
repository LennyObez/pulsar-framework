//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// SQLite open / busy / locked failure.
    #[error("sqlite connection failed")]
    Connection,

    /// SQL execution failed.
    #[error("sqlite query failed: {0}")]
    Query(String),

    /// Migration failed.
    #[error("sqlite migration failed: {0}")]
    Migration(String),

    #[doc(hidden)]
    #[error("placeholder smoke variant")]
    __PlaceholderSmokeOnly,
}

impl Error {
    #[doc(hidden)]
    pub(crate) fn __placeholder_smoke_only() -> Self { Self::__PlaceholderSmokeOnly }
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
