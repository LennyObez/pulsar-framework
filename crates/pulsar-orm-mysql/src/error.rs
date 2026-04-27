//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// MySQL connection failed.
    #[error("mysql connection failed")]
    Connection,

    /// SQL execution failed.
    #[error("mysql query failed: {0}")]
    Query(String),

    /// Migration failed.
    #[error("mysql migration failed: {0}")]
    Migration(String),
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
