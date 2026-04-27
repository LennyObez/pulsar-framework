//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// SSRF gate rejection modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Hostname resolved to an IANA-reserved or cloud-metadata IP.
    #[error("SSRF gate rejected: target IP {ip} is in the blocked range")]
    BlockedRange {
        /// IPv4 / IPv6 address the hostname resolved to (in the blocked range — IANA-reserved or cloud IMDS).
        ip: String,
    },

    /// DNS rebinding detected — gate-time and connect-time resolutions disagree.
    #[error("DNS rebinding detected: gate resolved {gate}, connect resolved {connect}")]
    DnsRebinding {
        /// IP the hostname resolved to at gate-time (when the allowlist check passed).
        gate: String,
        /// IP the hostname resolved to at connect-time (different from `gate` — rebinding).
        connect: String,
    },

    /// Hostname not in the configured allowlist for this outbound class.
    #[error("hostname not allowlisted for outbound class '{class}': {host}")]
    NotAllowlisted {
        /// Outbound class name (e.g. `webhook-out`, `oauth-introspection`, `imap-egress`).
        class: String,
        /// Hostname that was rejected because it is absent from the class's allowlist.
        host: String,
    },

    /// Hostname did not resolve to any IP at all.
    #[error("hostname did not resolve: {host}")]
    Unresolved {
        /// Hostname that failed to resolve (DNS NXDOMAIN, SERVFAIL, or timeout).
        host: String,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
