//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `EventSource` will be sealed so third-party adapters must register
//! through the macro path (which carries the replay-window contract).

pub(crate) trait Sealed {}
