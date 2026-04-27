//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `WorkflowStateStore` will be sealed so third-party stores must register
//! through the macro path (which carries the deterministic-replay contract
//! `spec/orchestration.tla` proves).

#[allow(dead_code)] // placeholder until per-sprint public traits use Sealed
pub(crate) trait Sealed {}
