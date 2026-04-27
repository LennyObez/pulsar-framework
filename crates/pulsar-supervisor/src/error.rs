//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Supervisor + worker lifecycle failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Worker exited abnormally; supervisor will apply the restart strategy.
    #[error("worker '{worker}' exited abnormally")]
    WorkerExited { worker: String },

    /// Restart-storm budget exhausted; supervisor escalating to parent.
    #[error("restart-storm budget exhausted ({restarts} in window)")]
    RestartStorm { restarts: u32 },

    /// Capability check failed at supervisor instantiation.
    #[error("supervisor capability rejected")]
    CapabilityRejected,
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
