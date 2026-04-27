//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 3B.2 implementation will re-export `WebSocketServer`,
//! `WebSocketSession`, `Message`, `CloseCode`, plus the dispatch
//! attribute `#[ws_handler]`.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
