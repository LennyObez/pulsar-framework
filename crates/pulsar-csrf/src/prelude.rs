//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Downstream code uses `use pulsar_csrf::prelude::*;` to bring the curated
//! public surface into scope. The Sprint 1.5 implementation will re-export
//! the public middleware factory + the `CsrfPolicy` builder + the
//! `csrf_exempt` attribute wrapper. Until then the prelude is intentionally
//! empty (the `pub use` lines land with the implementation).

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
