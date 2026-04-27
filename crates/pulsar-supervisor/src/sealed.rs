//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `Worker` will be sealed so worker types must register through the
//! macro path (carries the lifecycle hook contract).

#[allow(dead_code)] // placeholder until per-sprint public traits use Sealed
pub(crate) trait Sealed {}
