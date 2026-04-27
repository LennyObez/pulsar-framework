//! Crate-level error type per plan Section XVII.7 error strategy.
//!
//! `Error` enumerates every failure mode the CSRF middleware can surface,
//! paired with `thiserror::Error` for derived `Display` + `source()`.
//! Downstream code uses `pulsar_csrf::Result<T>` rather than the bare
//! `core::result::Result`. Each variant maps to an OWASP-classified
//! defence failure for audit and dashboard purposes.

use thiserror::Error;

/// CSRF defence failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Synchroniser token submitted in form/header does not HMAC-verify against the per-session secret.
    #[error("CSRF token mismatch")]
    InvalidToken,

    /// Required `Origin` or `Sec-Fetch-Site` header absent on a state-changing request.
    #[error("CSRF protective header missing")]
    MissingHeader,

    /// Token TTL exceeded; client must request a fresh token.
    #[error("CSRF token expired")]
    Expired,

    /// Double-submit cookie value does not match the form/header token.
    #[error("CSRF double-submit cookie mismatch")]
    CookieMismatch,
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
