//! Consent-aware notification dispatcher across push, SMS, email, web, in-app.
//!
//! Placeholder release for namespace reservation. Implementation arrives at the sprint
//! identified in `docs/plan.md` Section IV. Until then this crate exposes only the
//! version constant.

#![deny(missing_docs)]
#![forbid(unsafe_code)]

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
