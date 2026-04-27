//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `WebSocketHandler` will be sealed so third-party handlers must register
//! through the macro-generated path (which carries the lifecycle invariant
//! `spec/websocket.tla` proves).

pub(crate) trait Sealed {}
