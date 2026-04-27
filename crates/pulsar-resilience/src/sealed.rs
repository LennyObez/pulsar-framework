//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `FailureClassifier` will be sealed so third-party classifiers cannot
//! bypass the documented "transient vs permanent" categorisation.

pub(crate) trait Sealed {}
