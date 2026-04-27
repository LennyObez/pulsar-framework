//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 1.5 implementation will re-export the `IntegrityPolicy` builder +
//! the `compute_digest` helper + the template directive registration entry
//! point. Until then the prelude carries the error types only.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
