//! Integration tests for `argon2::hash_password_with_random_salt`.
//!
//! The CSPRNG-backed convenience hashes a password with a freshly-
//! generated 16-byte salt sourced from the OS entropy stream. Each
//! call must produce a unique PHC string (probabilistic — collisions
//! on 16-byte salts have probability ≈ 2⁻¹²⁸) that round-trips
//! correctly through [`verify_password`].

#![allow(clippy::expect_used, clippy::unwrap_used, clippy::panic)]

use pulsar_kernel::crypto::argon2::{
    Argon2idParams, hash_password_with_random_salt, verify_password,
};
use secrecy::SecretBox;

fn password_box(bytes: &[u8]) -> SecretBox<[u8]> {
    SecretBox::new(bytes.to_vec().into_boxed_slice())
}

/// Round-trip — produce a PHC string with a random salt and verify
/// the original password against it.
#[test]
fn argon2id_random_salt_round_trip() {
    let password = password_box(b"correct horse battery staple");
    let phc = hash_password_with_random_salt(&password, Argon2idParams::default())
        .expect("hash_password_with_random_salt should succeed");

    // PHC string must declare the OWASP-default cost parameters.
    assert!(phc.starts_with("$argon2id$v=19$m=19456,t=2,p=1$"));

    verify_password(&password, &phc).expect("verify of authentic password should succeed");
}

/// Two consecutive calls produce distinct PHC strings — the salt is
/// freshly drawn each call, so the encoded salt + resulting hash
/// differ even for the same `(password, params)` input.
#[test]
fn argon2id_random_salt_produces_distinct_phc_strings() {
    let password = password_box(b"correct horse battery staple");
    let phc_a = hash_password_with_random_salt(&password, Argon2idParams::default())
        .expect("hash a should succeed");
    let phc_b = hash_password_with_random_salt(&password, Argon2idParams::default())
        .expect("hash b should succeed");

    assert_ne!(
        phc_a, phc_b,
        "consecutive calls must produce distinct PHC strings (different random salts)",
    );

    // Both must verify the original password.
    verify_password(&password, &phc_a).expect("verify a should succeed");
    verify_password(&password, &phc_b).expect("verify b should succeed");
}

/// Wrong password is still rejected when the salt was randomly
/// generated.
#[test]
fn argon2id_random_salt_rejects_wrong_password() {
    use pulsar_kernel::error::Error;

    let password = password_box(b"correct horse battery staple");
    let wrong = password_box(b"correct horse battery stapler");

    let phc = hash_password_with_random_salt(&password, Argon2idParams::default())
        .expect("hash should succeed");

    match verify_password(&wrong, &phc) {
        Err(Error::PasswordVerifyFailed) => {} // expected
        other => panic!("expected PasswordVerifyFailed; got {other:?}"),
    }
}
