//! Smoke test per plan Section XVII.19 testing convention.

use pulsar_crypto_hacl_bindings::error::{Error, Result};

#[test]
fn version_is_non_empty() {
    assert!(!pulsar_crypto_hacl_bindings::VERSION.is_empty(), "VERSION must be a non-empty const");
}

#[test]
fn version_matches_cargo_pkg_version() {
    assert_eq!(pulsar_crypto_hacl_bindings::VERSION, env!("CARGO_PKG_VERSION"));
}

#[test]
fn result_alias_resolves() {
    fn _signature() -> Result<()> { Err(Error::AeadAuthFailed) }
    let _ = _signature();
}
