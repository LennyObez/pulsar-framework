//! Cross-Site Request Forgery (CSRF) protection middleware.
//!
//! Implements the OWASP-recommended CSRF defence pattern:
//!
//! * Per-session synchroniser token bound via HMAC (constant-time verify).
//! * Double-submit cookie cross-checked against the synchroniser token.
//! * `Origin` and `Sec-Fetch-Site` header validation per Fetch Metadata Request Headers
//!   (W3C, 2020).
//!
//! State-changing methods (`POST` / `PUT` / `PATCH` / `DELETE`) are gated; safe
//! methods (`GET` / `HEAD` / `OPTIONS`) bypass. Any `#[csrf_exempt]` opt-out lands
//! in the audit chain via `pulsar-audit`.
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51.
//! See `docs/plan.md` Section IV.11.1 (per-crate spec) and Section V.1.5
//! (implementing sprint). Creusot contract on the verify path per Decision 2.20
//! (formal verification scope).
//!
//! Placeholder release for namespace reservation. The implementation ships in `0.1.0`.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
