//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `EscalationBackend` will be sealed so third-party adapters cannot
//! bypass the audit-chain write that every escalation triggers.

pub(crate) trait Sealed {}
