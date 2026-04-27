//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! Driver-internal types implement parent-crate sealed traits; nothing is
//! exposed beyond the driver's own implementation surface.

#[allow(dead_code)] // placeholder until per-sprint public traits use Sealed
pub(crate) trait Sealed {}
