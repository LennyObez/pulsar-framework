//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `FailureClassifier` will be sealed so third-party classifiers cannot
//! bypass the documented "transient vs permanent" categorisation.

#[allow(dead_code)] // placeholder until per-sprint public traits use Sealed
pub(crate) trait Sealed {}
