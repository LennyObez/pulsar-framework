//! Smoke test per plan Section XVII.19 testing convention.

use pulsar_cloud_aws::error::{Error, Result};

#[test]
fn version_is_non_empty() {
    assert!(
        !pulsar_cloud_aws::VERSION.is_empty(),
        "VERSION must be a non-empty const"
    );
}

#[test]
fn version_matches_cargo_pkg_version() {
    assert_eq!(pulsar_cloud_aws::VERSION, env!("CARGO_PKG_VERSION"));
}

#[test]
fn result_alias_resolves() {
    fn _signature() -> Result<()> {
        Err(Error::CredentialResolution)
    }
    let _ = _signature();
}

#[test]
fn prelude_module_importable() {
    #[allow(unused_imports)]
    use pulsar_cloud_aws::prelude::*;
}
