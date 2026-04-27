//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `BroadcastingBackend` will be sealed so third-party adapters cannot
//! bypass the per-channel capability check.

pub(crate) trait Sealed {}
