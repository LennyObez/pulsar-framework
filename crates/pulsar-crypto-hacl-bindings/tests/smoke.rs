//! Smoke test per plan Section XVII.19 testing convention.
//!
//! Validates that the HACL\* FFI surface compiles, links, and produces a
//! known-correct output for a canonical primitive (SHA-256 of empty
//! input). The hash value `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`
//! is the FIPS 180-4 specified output for SHA-256 of a zero-length
//! message and is the most-cited test vector in the cryptographic
//! literature.
//!
//! This test exercises one path of the FFI surface end-to-end:
//! - The vendored `hacl-c/src/EverCrypt_Hash.c` compiled cleanly,
//! - The bindgen-generated declarations linked correctly,
//! - The runtime CPU dispatcher (`EverCrypt_AutoConfig2_init`) initialises,
//! - The single-shot hash function returns the expected bytes.
//!
//! Per Section XVII.19, this is the smoke layer of the testing
//! hierarchy. Property tests, fuzz harnesses, and Creusot contracts on
//! the safe wrappers land in `pulsar-kernel::crypto` (Sprint 1.1.B+).

use pulsar_crypto_hacl_bindings::error::{Error, Result};

#[test]
fn version_is_non_empty() {
    assert!(
        !pulsar_crypto_hacl_bindings::VERSION.is_empty(),
        "VERSION must be a non-empty const"
    );
}

#[test]
fn version_matches_cargo_pkg_version() {
    assert_eq!(
        pulsar_crypto_hacl_bindings::VERSION,
        env!("CARGO_PKG_VERSION")
    );
}

#[test]
fn result_alias_resolves() {
    fn check_signature() -> Result<()> {
        Err(Error::AeadAuthFailed)
    }
    let _ = check_signature();
}

/// SHA-256 of empty input — the FIPS 180-4 canonical test vector.
///
/// Exercises the full FFI pipeline: vendored C compiled, bindgen
/// declarations linked, runtime CPU dispatcher initialised, single-shot
/// hash returns the expected 32-byte digest.
#[cfg(not(hacl_placeholder))]
#[test]
fn sha256_empty_input_matches_fips_180_4_test_vector() {
    use pulsar_crypto_hacl_bindings::ffi;

    // FIPS 180-4 § B.1 test vector for SHA-256 of empty input.
    const EXPECTED: [u8; 32] = [
        0xe3, 0xb0, 0xc4, 0x42, 0x98, 0xfc, 0x1c, 0x14, 0x9a, 0xfb, 0xf4, 0xc8, 0x99, 0x6f, 0xb9,
        0x24, 0x27, 0xae, 0x41, 0xe4, 0x64, 0x9b, 0x93, 0x4c, 0xa4, 0x95, 0x99, 0x1b, 0x78, 0x52,
        0xb8, 0x55,
    ];

    let mut digest = [0u8; 32];

    // SAFETY: AutoConfig2_init takes no parameters and returns void; it
    // initialises EverCrypt's runtime CPU feature dispatcher and is
    // idempotent. Calling it without C-side state to clean up is safe.
    unsafe {
        ffi::EverCrypt_AutoConfig2_init();
    }

    // SAFETY: EverCrypt_Hash_Incremental_hash with empty input requires:
    //   - `a` is a valid Spec_Hash_Definitions_hash_alg (SHA2_256 = 1 by header),
    //   - `output` points to at least 32 writable bytes (SHA-256 digest length),
    //   - `input` may be NULL when `input_len == 0` per the HACL* contract.
    // All preconditions satisfied: SHA2_256 alg constant is 1, `digest`
    // is a 32-byte stack array, input length is 0 with a non-null
    // sentinel pointer (HACL* dereferences only when input_len > 0).
    let mut empty_marker: u8 = 0; // sentinel; never read because input_len = 0
    // The hash-alg parameter is `uint8_t` C-side but bindgen emits the
    // module-const enum variants as `u32` (default `EnumVariation::ModuleConsts`
    // representation). All Spec_Hash_Definitions_* constants are < 16, so
    // narrowing to u8 is information-preserving.
    #[allow(clippy::cast_possible_truncation)]
    let alg = ffi::Spec_Hash_Definitions_SHA2_256 as u8;
    unsafe {
        ffi::EverCrypt_Hash_Incremental_hash(alg, digest.as_mut_ptr(), &raw mut empty_marker, 0);
    }

    assert_eq!(
        digest, EXPECTED,
        "SHA-256(\"\") FIPS 180-4 test vector mismatch — HACL* FFI is broken"
    );
}
