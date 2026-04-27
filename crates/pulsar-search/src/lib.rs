//! Tantivy-backed search abstraction with i18n tokenisation and field policies.
//!
//! Placeholder release for namespace reservation. The implementation ships
//! at the sprint identified in `docs/plan.md` Section IV (per-crate spec)
//! and Section V (implementing sprint). Until then this crate exposes only
//! the version constant plus the standard module skeleton (prelude / error /
//! sealed) per Section XVII.2 module layout.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
