//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Resilience primitive shed-or-fail modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Circuit breaker is open; downstream call short-circuited.
    #[error("circuit breaker open")]
    CircuitOpen,

    /// Bulkhead semaphore exhausted; downstream call rejected.
    #[error("bulkhead at capacity")]
    BulkheadAtCapacity,

    /// Per-call timeout elapsed before downstream completed.
    #[error("call timeout after {elapsed_ms} ms")]
    Timeout {
        /// Milliseconds elapsed when the timeout fired (should match the configured per-call deadline).
        elapsed_ms: u64,
    },

    /// Retry budget exceeded after {attempts} attempts.
    #[error("retry budget exceeded after {attempts} attempts")]
    RetryBudgetExceeded {
        /// Total number of attempts (initial + retries) before the budget was exhausted.
        attempts: u32,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
