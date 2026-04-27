//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Saga compensation failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Forward step failed; saga entered compensation phase.
    #[error("saga forward step '{step}' failed; compensation triggered")]
    ForwardStepFailed {
        /// Logical name of the saga step that failed during forward execution (matches the step's registered identifier).
        step: String,
    },

    /// Compensating step itself failed — escalation required (manual intervention).
    #[error("saga compensation step '{step}' failed; escalation required")]
    CompensationFailed {
        /// Logical name of the saga step whose compensating action itself failed (manual intervention required per the saga semantics).
        step: String,
    },

    /// Saga journal corruption / inconsistency detected at compensation replay.
    #[error("saga journal inconsistent at replay")]
    JournalInconsistent,
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
