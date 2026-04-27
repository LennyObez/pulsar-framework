//! Smoke test per plan Section XVII.19 testing convention.

use pulsar_cloud_azure::error::{Error, Result};

#[test]
fn version_is_non_empty() {
    assert!(!pulsar_cloud_azure::VERSION.is_empty(), "VERSION must be a non-empty const");
}

#[test]
fn version_matches_cargo_pkg_version() {
    assert_eq!(pulsar_cloud_azure::VERSION, env!("CARGO_PKG_VERSION"));
}

#[test]
fn result_alias_resolves() {
    fn _signature() -> Result<()> { Err(Error::__placeholder_smoke_only()) }
    let _ = _signature();
}

#[test]
fn prelude_module_importable() {
    #[allow(unused_imports)]
    use pulsar_cloud_azure::prelude::*;
}
