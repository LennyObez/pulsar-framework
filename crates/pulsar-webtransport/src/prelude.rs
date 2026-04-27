//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 3B.2 implementation will re-export `WebTransportSession`,
//! `Stream`, `Datagram`, plus the dispatcher entry points.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
