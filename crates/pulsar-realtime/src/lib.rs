//! Consolidated WebSocket, SSE, WebTransport, broadcasting fan-out (Section 16.14).
//!
//! Placeholder release for namespace reservation. Implementation arrives at the sprint
//! identified in `docs/plan.md` Section IV. Until then this crate exposes only the
//! version constant.

#![deny(missing_docs)]
#![forbid(unsafe_code)]

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
