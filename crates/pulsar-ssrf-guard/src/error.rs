//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// SSRF gate rejection modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Hostname resolved to an IANA-reserved or cloud-metadata IP.
    #[error("SSRF gate rejected: target IP {ip} is in the blocked range")]
    BlockedRange { ip: String },

    /// DNS rebinding detected — gate-time and connect-time resolutions disagree.
    #[error("DNS rebinding detected: gate resolved {gate}, connect resolved {connect}")]
    DnsRebinding { gate: String, connect: String },

    /// Hostname not in the configured allowlist for this outbound class.
    #[error("hostname not allowlisted for outbound class '{class}': {host}")]
    NotAllowlisted { class: String, host: String },

    /// Hostname did not resolve to any IP at all.
    #[error("hostname did not resolve: {host}")]
    Unresolved { host: String },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
