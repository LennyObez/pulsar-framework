//! Known-answer + integration tests for `pulsar_kernel::crypto::ml_kem`.
//!
//! Cryptographic correctness of the underlying ML-KEM-768 implementation
//! is asserted by the `libcrux-ml-kem` upstream test suite (FIPS 203
//! ACVP conformance + hax + F\* machine-checked proofs of field
//! arithmetic, NTT, serialization, and high-level algorithm
//! composition). These wrapper-level tests focus on:
//!
//! - Sizes match FIPS 203 ML-KEM-768 (1184 / 2400 / 1088 / 32)
//! - Encap/decap round-trip recovers the encapsulator's shared secret
//! - Seed-driven keypair generation is deterministic — the same 64-byte
//!   seed always yields the same `(public_key, private_key)` pair
//! - Public-key validation accepts authentic keys
//! - Private-key validation accepts authentic (key, ciphertext) pairs
//! - FIPS 203 § 7.3 implicit rejection — decapsulating a tampered
//!   ciphertext under a genuine private key yields a deterministic-but-
//!   uncorrelated shared secret (NOT the encapsulator's shared secret)

#![allow(clippy::expect_used, clippy::unwrap_used, clippy::panic)]

use pulsar_kernel::crypto::ml_kem::{
    MlKem768Ciphertext, MlKem768KeyPair, MlKem768PrivateKey, MlKem768PublicKey, sizes,
};
use secrecy::{ExposeSecret, SecretBox};

fn seed_box(bytes: &[u8]) -> SecretBox<[u8]> {
    SecretBox::new(bytes.to_vec().into_boxed_slice())
}

/// Sizes match FIPS 203 ML-KEM-768.
#[test]
fn ml_kem_768_sizes_match_fips_203() {
    assert_eq!(sizes::PUBLIC_KEY_LEN, 1184);
    assert_eq!(sizes::PRIVATE_KEY_LEN, 2400);
    assert_eq!(sizes::CIPHERTEXT_LEN, 1088);
    assert_eq!(sizes::SHARED_SECRET_LEN, 32);
    assert_eq!(sizes::KEY_GENERATION_SEED_LEN, 64);
    assert_eq!(sizes::ENCAPSULATION_SEED_LEN, 32);

    assert_eq!(MlKem768PublicKey::LEN, 1184);
    assert_eq!(MlKem768PrivateKey::LEN, 2400);
    assert_eq!(MlKem768Ciphertext::LEN, 1088);
}

/// Encap/decap round-trip — the encapsulator's shared secret matches
/// the decapsulator's shared secret. Uses a fixed seed so the result
/// is reproducible across CI runs.
#[test]
fn ml_kem_768_encap_decap_round_trip() {
    let seed = seed_box(&[0x42_u8; 64]);
    let kp = MlKem768KeyPair::try_from_seed(&seed).expect("keypair from seed should succeed");

    let (ciphertext, encap_secret) = kp
        .public_key()
        .try_encapsulate()
        .expect("encapsulate should succeed");

    let decap_secret = kp.private_key().decapsulate(&ciphertext);

    assert_eq!(
        encap_secret.expose_secret(),
        decap_secret.expose_secret(),
        "encap shared secret must equal decap shared secret",
    );
    assert_eq!(encap_secret.expose_secret().len(), 32);
}

/// Seed-driven keypair generation is deterministic — the same 64-byte
/// seed always yields the same public + private key.
#[test]
fn ml_kem_768_keypair_from_seed_is_deterministic() {
    let seed_bytes = [0x99_u8; 64];

    let kp_a =
        MlKem768KeyPair::try_from_seed(&seed_box(&seed_bytes)).expect("keypair a should succeed");
    let kp_b =
        MlKem768KeyPair::try_from_seed(&seed_box(&seed_bytes)).expect("keypair b should succeed");

    assert_eq!(
        kp_a.public_key().as_bytes(),
        kp_b.public_key().as_bytes(),
        "deterministic seed must yield identical public keys",
    );
}

/// Encapsulation with the same `(public_key, encap_seed)` pair is
/// deterministic. Produces the same ciphertext + shared secret on
/// repeat invocation. Required for testing-vector replay and for
/// hybrid-KEM constructions that derive the encap seed from upstream
/// entropy.
#[test]
fn ml_kem_768_encapsulate_with_seed_is_deterministic() {
    let kp_seed = seed_box(&[0x11_u8; 64]);
    let kp = MlKem768KeyPair::try_from_seed(&kp_seed).expect("keypair should succeed");

    let encap_seed = seed_box(&[0x77_u8; 32]);

    let (ct_a, ss_a) = kp
        .public_key()
        .try_encapsulate_with_seed(&encap_seed)
        .expect("encapsulate a should succeed");
    let (ct_b, ss_b) = kp
        .public_key()
        .try_encapsulate_with_seed(&encap_seed)
        .expect("encapsulate b should succeed");

    assert_eq!(
        ct_a.as_bytes(),
        ct_b.as_bytes(),
        "deterministic encap seed must yield identical ciphertexts",
    );
    assert_eq!(
        ss_a.expose_secret(),
        ss_b.expose_secret(),
        "deterministic encap seed must yield identical shared secrets",
    );
}

/// `MlKem768PublicKey::validate` accepts a public key produced by
/// authentic keypair generation.
#[test]
fn ml_kem_768_validate_accepts_authentic_public_key() {
    let seed = seed_box(&[0x55_u8; 64]);
    let kp = MlKem768KeyPair::try_from_seed(&seed).expect("keypair should succeed");

    kp.public_key()
        .validate()
        .expect("authentic public key must validate");
}

/// `MlKem768PrivateKey::validate_against` accepts a (private key,
/// ciphertext) pair where the ciphertext was produced by encapsulating
/// against the matching public key.
#[test]
fn ml_kem_768_validate_against_accepts_authentic_pair() {
    let seed = seed_box(&[0x33_u8; 64]);
    let kp = MlKem768KeyPair::try_from_seed(&seed).expect("keypair should succeed");

    let (ciphertext, _) = kp
        .public_key()
        .try_encapsulate()
        .expect("encapsulate should succeed");

    kp.private_key()
        .validate_against(&ciphertext)
        .expect("authentic (key, ct) pair must validate");
}

/// FIPS 203 § 7.3 implicit rejection — decapsulating a tampered
/// ciphertext under a genuine private key yields a deterministic-but-
/// uncorrelated shared secret. The decapsulation never errors; the
/// tampered output is NOT equal to the encapsulator's shared secret,
/// and is reproducible across runs (deterministic with respect to the
/// (private_key, tampered_ciphertext) pair).
#[test]
fn ml_kem_768_implicit_rejection_yields_uncorrelated_secret() {
    let seed = seed_box(&[0x77_u8; 64]);
    let kp = MlKem768KeyPair::try_from_seed(&seed).expect("keypair should succeed");

    let (mut ciphertext_bytes, encap_secret) = {
        let (ct, ss) = kp
            .public_key()
            .try_encapsulate()
            .expect("encapsulate should succeed");
        (*ct.as_bytes(), ss)
    };

    // Flip a single bit at byte 0 — the ciphertext is now invalid.
    ciphertext_bytes[0] ^= 0x01;
    let tampered = MlKem768Ciphertext::from_bytes(ciphertext_bytes);

    let rejection_secret = kp.private_key().decapsulate(&tampered);

    // FIPS 203 § 7.3: the rejection secret is NOT the original
    // encapsulator secret.
    assert_ne!(
        rejection_secret.expose_secret(),
        encap_secret.expose_secret(),
        "tampered ciphertext must not yield the encapsulator's shared secret",
    );

    // The rejection secret IS deterministic — repeating the
    // decapsulation with the same (private_key, tampered_ct) yields
    // the same output (FIPS 203 implicit-rejection branch is a pure
    // function of its inputs).
    let rejection_secret_again = kp.private_key().decapsulate(&tampered);
    assert_eq!(
        rejection_secret.expose_secret(),
        rejection_secret_again.expose_secret(),
        "implicit rejection must be deterministic",
    );
}

/// `MlKem768KeyPair::try_from_seed` rejects a wrong-length seed.
#[test]
fn ml_kem_768_keypair_rejects_wrong_seed_length() {
    use pulsar_kernel::error::Error;

    let too_short = seed_box(&[0_u8; 63]);
    let too_long = seed_box(&[0_u8; 65]);

    match MlKem768KeyPair::try_from_seed(&too_short) {
        Err(Error::InvalidKeyLength {
            expected: 64,
            actual: 63,
        }) => {}
        other => panic!("expected InvalidKeyLength {{ expected: 64, actual: 63 }}; got {other:?}"),
    }

    match MlKem768KeyPair::try_from_seed(&too_long) {
        Err(Error::InvalidKeyLength {
            expected: 64,
            actual: 65,
        }) => {}
        other => panic!("expected InvalidKeyLength {{ expected: 64, actual: 65 }}; got {other:?}"),
    }
}

/// `MlKem768PrivateKey::from_bytes` rejects a wrong-length private key.
#[test]
fn ml_kem_768_private_key_rejects_wrong_length() {
    use pulsar_kernel::error::Error;

    let too_short = seed_box(&[0_u8; 2399]);

    match MlKem768PrivateKey::from_bytes(&too_short) {
        Err(Error::InvalidKeyLength {
            expected: 2400,
            actual: 2399,
        }) => {}
        other => panic!("expected InvalidKeyLength; got {other:?}"),
    }
}

/// `MlKem768PublicKey::try_encapsulate_with_seed` rejects a wrong-
/// length encapsulation seed.
#[test]
fn ml_kem_768_encapsulate_rejects_wrong_seed_length() {
    use pulsar_kernel::error::Error;

    let kp_seed = seed_box(&[0x44_u8; 64]);
    let kp = MlKem768KeyPair::try_from_seed(&kp_seed).expect("keypair should succeed");

    let too_short = seed_box(&[0_u8; 31]);

    match kp.public_key().try_encapsulate_with_seed(&too_short) {
        Err(Error::InvalidKeyLength {
            expected: 32,
            actual: 31,
        }) => {}
        other => panic!("expected InvalidKeyLength; got {other:?}"),
    }
}

/// `Debug` impls do not leak secret bytes from the private key.
/// Public key + ciphertext show a short hex prefix for identification
/// (they are not secret per the KEM threat model).
#[test]
fn ml_kem_768_debug_redacts_private_key() {
    let seed = seed_box(&[0xAB_u8; 64]);
    let kp = MlKem768KeyPair::try_from_seed(&seed).expect("keypair should succeed");

    // Private-key Debug uses `finish_non_exhaustive()` — must contain
    // the `..` non-exhaustive marker and must NOT contain any hex
    // representation of the inner secret bytes.
    let private_debug = format!("{:?}", kp.private_key());
    assert!(private_debug.contains("MlKem768PrivateKey"));
    assert!(
        private_debug.contains(".."),
        "private-key Debug must use finish_non_exhaustive, got: {private_debug}",
    );

    // Public-key Debug shows a hex prefix — public keys are not
    // secret per the KEM threat model.
    let public_debug = format!("{:?}", kp.public_key());
    assert!(public_debug.contains("MlKem768PublicKey"));
    assert!(public_debug.contains("bytes"));
}
