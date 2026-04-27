//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `EscalationBackend` will be sealed so third-party adapters cannot
//! bypass the audit-chain write that every escalation triggers.

#[allow(dead_code)]  // placeholder until per-sprint public traits use Sealed
pub(crate) trait Sealed {}
