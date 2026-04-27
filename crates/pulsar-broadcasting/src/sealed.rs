//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `BroadcastingBackend` will be sealed so third-party adapters cannot
//! bypass the per-channel capability check.

#[allow(dead_code)] // placeholder until per-sprint public traits use Sealed
pub(crate) trait Sealed {}
