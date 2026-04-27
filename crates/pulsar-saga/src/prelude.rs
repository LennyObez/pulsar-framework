//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 3E.1 implementation will re-export `Saga`, `SagaStep`,
//! `Compensation`, plus the `saga!` builder macro.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
