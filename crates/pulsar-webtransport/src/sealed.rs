//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `WebTransportHandler` will be sealed so third-party handlers must
//! register through the macro path (carries the per-stream + per-datagram
//! backpressure invariants).

pub(crate) trait Sealed {}
