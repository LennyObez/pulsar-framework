//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `SagaJournal` will be sealed so third-party journals must register
//! through the macro path (carries the compensation-ordering contract
//! `spec/orchestration.tla` + `spec/multi-region-saga.tla` prove).

#[allow(dead_code)]  // placeholder until per-sprint public traits use Sealed
pub(crate) trait Sealed {}
