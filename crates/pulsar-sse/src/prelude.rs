//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 3B.2 implementation will re-export `EventStream`, `Event`,
//! plus the `sse_handler!` macro that wires an HTTP route to a stream.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
