//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Workflow execution + storage failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Activity exhausted its retry budget without success.
    #[error("activity '{activity}' exceeded retry budget after {attempts} attempts")]
    ActivityRetryExhausted {
        /// Logical activity name as registered with the workflow runtime.
        activity: String,
        /// Total number of execution attempts (initial + retries) before the budget was exhausted.
        attempts: u32,
    },

    /// Workflow timed out (per-workflow or per-activity deadline).
    #[error("workflow timeout: {scope} (elapsed {elapsed_ms} ms)")]
    Timeout {
        /// Static scope identifier indicating which deadline elapsed (`workflow`, `activity`, `step`).
        scope: &'static str,
        /// Milliseconds elapsed when the timeout fired.
        elapsed_ms: u64,
    },

    /// Durability backend (PostgreSQL by default) read/write failed.
    #[error("workflow state-store unavailable")]
    StateStoreUnavailable,

    /// Deterministic-replay invariant violated (workflow code changed between runs in non-determinism-safe way).
    #[error("non-deterministic workflow replay detected")]
    NonDeterministicReplay,

    /// Signal received for a workflow not in a state that accepts it.
    #[error("signal '{signal}' rejected: workflow in state '{state}'")]
    SignalRejected {
        /// Signal name as published by the caller (rejected because the workflow is not in an accepting state).
        signal: String,
        /// Current workflow state name (in which signals are not accepted, e.g. `Completed`, `Failed`, `Compensating`).
        state: String,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
