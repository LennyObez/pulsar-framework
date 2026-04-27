//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `EventSource` will be sealed so third-party adapters must register
//! through the macro path (which carries the replay-window contract).

#[allow(dead_code)]  // placeholder until per-sprint public traits use Sealed
pub(crate) trait Sealed {}
