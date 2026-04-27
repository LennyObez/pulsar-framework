//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// ClickHouse connection failed.
    #[error("clickhouse connection failed")]
    Connection,

    /// Query execution failed.
    #[error("clickhouse query failed: {0}")]
    Query(String),

    /// Bulk insert failed mid-batch.
    #[error("clickhouse bulk insert failed at row {row}: {reason}")]
    BulkInsert {
        /// Zero-based row index within the bulk-insert batch where the failure occurred.
        row: usize,
        /// Free-form reason returned by the ClickHouse server (typically a constraint violation or column-type mismatch).
        reason: String,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
