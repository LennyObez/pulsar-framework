//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// SSE channel failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Subscriber capability rejected at SSE handshake.
    #[error("SSE subscription capability rejected")]
    CapabilityRejected,

    /// Backpressure queue full; slow client cannot keep up.
    #[error("SSE backpressure overflow; closing stream")]
    BackpressureOverflow,

    /// Replay window for `Last-Event-ID` exhausted; client must reload state.
    #[error("SSE replay window exhausted (last seen {last_id})")]
    ReplayExhausted {
        /// Most recent event ID the client successfully received per the WHATWG `Last-Event-ID` reconnection protocol — the requested replay range starts after this ID and falls outside the configured retention window.
        last_id: String,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
