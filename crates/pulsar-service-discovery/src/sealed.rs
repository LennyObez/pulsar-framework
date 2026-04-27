//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `Resolver` will be sealed so third-party adapters cannot bypass the
//! health-check + capability invariants the macro path enforces.

pub(crate) trait Sealed {}
