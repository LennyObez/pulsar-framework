//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! The implementation ships in `0.1.0`; this prelude re-exports the error
//! types until the driver-specific public surface lands.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
