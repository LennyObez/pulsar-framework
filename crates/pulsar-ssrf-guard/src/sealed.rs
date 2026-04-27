//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `Resolver` will be sealed so third-party adapters cannot bypass the
//! double-resolve invariant that defeats DNS rebinding.

#[allow(dead_code)] // placeholder until per-sprint public traits use Sealed
pub(crate) trait Sealed {}
