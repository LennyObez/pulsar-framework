//! Known-answer + integration tests for `pulsar_kernel::crypto::argon2`.
//!
//! Cryptographic correctness of the underlying Argon2id implementation
//! is asserted by the RustCrypto `argon2` crate's upstream test suite
//! (audited per Decision 2.60 — RustCrypto is the audited-but-not-
//! formally-verified slot in Pulsar's multi-source crypto stack).
//! These tests focus on wrapper-level correctness:
//!
//! - Parameter validation (RFC 9106 § 3.1 admissible range)
//! - PHC string format (algorithm tag, parameters, salt encoding)
//! - Round-trip hash → verify
//! - Cross-algorithm rejection (Argon2i / Argon2d strings rejected)
//! - Tamper detection on the PHC string and password
//! - `derive_key` length + parameter-mismatch error variants
//!
//! Plus a pinned KAT vector in `argon2id_derive_key_kat` that catches
//! upstream output regressions.

#![allow(clippy::expect_used, clippy::unwrap_used, clippy::panic)]

use pulsar_kernel::crypto::argon2::{Argon2idParams, derive_key, hash_password, verify_password};
use pulsar_kernel::error::Error;
use secrecy::SecretBox;

fn password_box(bytes: &[u8]) -> SecretBox<[u8]> {
    SecretBox::new(bytes.to_vec().into_boxed_slice())
}

/// Pinned KAT vector — Argon2id v0x13 with m=32 KiB, t=2, p=1,
/// output=32 over (password="password", salt="somesaltsalty"). The
/// expected hex output captures the RustCrypto `argon2 0.5` substrate's
/// behaviour at vendoring time; a regression here means upstream
/// changed Argon2id output (which would be a security-relevant break
/// of the deterministic-spec property).
///
/// Reference: derived locally via RustCrypto argon2 0.5.3.
#[test]
fn argon2id_derive_key_kat() {
    let password = password_box(b"password");
    let salt = b"somesaltsalty";
    let params = Argon2idParams::new(32, 2, 1, 32);

    let mut output = [0_u8; 32];
    derive_key(&password, salt, params, &mut output).expect("derive_key should succeed");

    let expected = hex::decode("cbbec28d1ae506a2814c1b4a0d82b06d490f15159d01b845e969e55125e17cd2")
        .expect("valid hex");
    assert_eq!(
        output.as_slice(),
        expected.as_slice(),
        "Argon2id KAT regression — upstream argon2 crate output changed",
    );
}

/// Round-trip: hash_password produces a PHC string that verify_password
/// accepts under the original password.
#[test]
fn argon2id_hash_verify_round_trip() {
    let password = password_box(b"correct horse battery staple");
    let salt = b"sixteen-byte-salt-for-tests";
    let params = Argon2idParams::default();

    let phc = hash_password(&password, salt, params).expect("hash_password should succeed");
    assert!(phc.starts_with("$argon2id$v=19$m=19456,t=2,p=1$"));

    verify_password(&password, &phc).expect("verify_password should accept authentic password");
}

/// `verify_password` returns `PasswordVerifyFailed` when the supplied
/// password differs from the one originally hashed.
#[test]
fn argon2id_verify_rejects_wrong_password() {
    let password = password_box(b"correct horse battery staple");
    let wrong_password = password_box(b"correct horse battery stapler");
    let salt = b"sixteen-byte-salt-for-tests";
    let params = Argon2idParams::default();

    let phc = hash_password(&password, salt, params).expect("hash_password should succeed");

    match verify_password(&wrong_password, &phc) {
        Err(Error::PasswordVerifyFailed) => {} // expected
        other => panic!("expected PasswordVerifyFailed; got {other:?}"),
    }
}

/// `verify_password` returns `InvalidPhcString` when the input is not
/// a parseable PHC string at all (e.g., free-form text without the
/// leading `$<algo>$` structure).
#[test]
fn argon2id_verify_rejects_malformed_phc_string() {
    let password = password_box(b"any password");
    let malformed = "this is not a PHC string";

    match verify_password(&password, malformed) {
        Err(Error::InvalidPhcString) => {} // expected
        other => panic!("expected InvalidPhcString; got {other:?}"),
    }
}

/// `verify_password` rejects a non-Argon2id PHC string (the wrapper
/// exposes only Argon2id; silently accepting Argon2d / Argon2i would
/// let a misconfigured caller verify against a weaker variant).
#[test]
fn argon2id_verify_rejects_non_argon2id_phc_string() {
    let password = password_box(b"any password");
    // A PHC string for Argon2i (single-pass, side-channel-resistant
    // but weaker against time-memory trade-off) — Pulsar's surface
    // accepts only Argon2id.
    let argon2i_phc =
        "$argon2i$v=19$m=4096,t=3,p=1$c29tZXNhbHQ$LpdRztpFlULY7XCIugWomYIVXm9Ws9zQTRZkFXWaqvI";

    match verify_password(&password, argon2i_phc) {
        Err(Error::InvalidPhcString) => {} // expected
        other => panic!("expected InvalidPhcString for Argon2i string; got {other:?}"),
    }
}

/// `validate` rejects parameters falling below the RFC 9106 § 3.1
/// admissible range.
#[test]
fn argon2id_params_validation_below_minimum() {
    // p_cost = 0
    match Argon2idParams::new(64, 2, 0, 32).validate() {
        Err(Error::InvalidArgon2Params { .. }) => {}
        other => panic!("expected InvalidArgon2Params for p_cost=0; got {other:?}"),
    }
    // t_cost = 0
    match Argon2idParams::new(64, 0, 1, 32).validate() {
        Err(Error::InvalidArgon2Params { .. }) => {}
        other => panic!("expected InvalidArgon2Params for t_cost=0; got {other:?}"),
    }
    // m_cost < 8 * p_cost (8 lanes * 8 KiB minimum = 64; m=63 fails)
    match Argon2idParams::new(7, 2, 1, 32).validate() {
        Err(Error::InvalidArgon2Params { .. }) => {}
        other => panic!("expected InvalidArgon2Params for m_cost < 8*p_cost; got {other:?}"),
    }
    // output_len < 4
    match Argon2idParams::new(64, 2, 1, 3).validate() {
        Err(Error::InvalidArgon2Params { .. }) => {}
        other => panic!("expected InvalidArgon2Params for output_len=3; got {other:?}"),
    }
}

/// `derive_key` rejects an output buffer whose length differs from
/// `params.output_len`.
#[test]
fn argon2id_derive_key_rejects_mismatched_output() {
    let password = password_box(b"password");
    let salt = b"somesaltsalty";
    let params = Argon2idParams::new(32, 2, 1, 32);
    let mut output = [0_u8; 16]; // params expects 32 bytes

    match derive_key(&password, salt, params, &mut output) {
        Err(Error::InvalidOutputLength {
            expected: 32,
            actual: 16,
        }) => {}
        other => panic!("expected InvalidOutputLength; got {other:?}"),
    }
}

/// `derive_key` rejects a salt shorter than 8 bytes (RFC 9106 § 3.1
/// minimum).
#[test]
fn argon2id_derive_key_rejects_short_salt() {
    let password = password_box(b"password");
    let salt = b"1234567"; // 7 bytes, RFC 9106 minimum is 8
    let params = Argon2idParams::new(32, 2, 1, 32);
    let mut output = [0_u8; 32];

    match derive_key(&password, salt, params, &mut output) {
        Err(Error::InvalidArgon2Params { .. }) => {}
        other => panic!("expected InvalidArgon2Params for short salt; got {other:?}"),
    }
}

/// `hash_password` rejects a salt shorter than 8 bytes.
#[test]
fn argon2id_hash_password_rejects_short_salt() {
    let password = password_box(b"password");
    let short_salt = b"1234567";
    let params = Argon2idParams::default();

    match hash_password(&password, short_salt, params) {
        Err(Error::InvalidArgon2Params { .. }) => {}
        other => panic!("expected InvalidArgon2Params for short salt; got {other:?}"),
    }
}

/// Default parameters validate successfully and match OWASP's "minimum
/// recommended" Argon2id profile.
#[test]
fn argon2id_default_params_validate() {
    let params = Argon2idParams::default();
    assert_eq!(params.m_cost, 19_456);
    assert_eq!(params.t_cost, 2);
    assert_eq!(params.p_cost, 1);
    assert_eq!(params.output_len, 32);
    params.validate().expect("default params should validate");
}
