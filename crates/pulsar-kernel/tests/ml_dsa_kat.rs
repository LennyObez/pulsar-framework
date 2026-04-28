//! Known-answer + integration tests for `pulsar_kernel::crypto::ml_dsa`.
//!
//! Cryptographic correctness of the underlying ML-DSA-65 implementation
//! is asserted by the `libcrux-ml-dsa` upstream test suite (FIPS 204
//! ACVP conformance + hax + F\* machine-checked proofs). These
//! wrapper-level tests focus on:
//!
//! - Sizes match FIPS 204 ML-DSA-65 (4032 / 1952 / 3309 / 32 / 32 / 255)
//! - Sign/verify round-trip recovers `Ok(())`
//! - Seed-driven keypair generation is deterministic
//! - Signing with a fixed randomness seed is deterministic
//! - Verification rejects tampered messages → `SignatureVerifyFailed`
//! - Verification rejects tampered signatures → `SignatureVerifyFailed`
//! - Wrong-context verification fails (FIPS 204 § 5.2 domain separation)
//! - Verification rejects wrong verification keys
//! - Length-validation paths surface the right `Error` variants

#![allow(clippy::expect_used, clippy::unwrap_used, clippy::panic)]

use pulsar_kernel::crypto::ml_dsa::{
    MlDsa65KeyPair, MlDsa65Signature, MlDsa65SigningKey, MlDsa65VerificationKey, sizes,
};
use secrecy::SecretBox;

fn seed_box(bytes: &[u8]) -> SecretBox<[u8]> {
    SecretBox::new(bytes.to_vec().into_boxed_slice())
}

/// Sizes match FIPS 204 ML-DSA-65.
#[test]
fn ml_dsa_65_sizes_match_fips_204() {
    assert_eq!(sizes::SIGNING_KEY_LEN, 4032);
    assert_eq!(sizes::VERIFICATION_KEY_LEN, 1952);
    assert_eq!(sizes::SIGNATURE_LEN, 3309);
    assert_eq!(sizes::KEY_GENERATION_RANDOMNESS_LEN, 32);
    assert_eq!(sizes::SIGNING_RANDOMNESS_LEN, 32);
    assert_eq!(sizes::MAX_CONTEXT_LEN, 255);

    assert_eq!(MlDsa65SigningKey::LEN, 4032);
    assert_eq!(MlDsa65VerificationKey::LEN, 1952);
    assert_eq!(MlDsa65Signature::LEN, 3309);
}

/// Sign/verify round-trip — the verifier accepts an authentic
/// signature produced by the matching signing key.
#[test]
fn ml_dsa_65_sign_verify_round_trip() {
    let seed = seed_box(&[0x42_u8; 32]);
    let kp = MlDsa65KeyPair::try_from_seed(&seed).expect("keypair from seed should succeed");

    let message = b"Pulsar Phase 1.1.C.2 ML-DSA-65 round-trip vector";
    let context = b"";
    let signature = kp
        .signing_key()
        .try_sign(message, context)
        .expect("sign should succeed");

    kp.verification_key()
        .verify(message, context, &signature)
        .expect("authentic signature must verify");
}

/// Seed-driven keypair generation is deterministic — the same 32-byte
/// seed always yields the same verification key.
#[test]
fn ml_dsa_65_keypair_from_seed_is_deterministic() {
    let seed_bytes = [0x99_u8; 32];

    let kp_a =
        MlDsa65KeyPair::try_from_seed(&seed_box(&seed_bytes)).expect("keypair a should succeed");
    let kp_b =
        MlDsa65KeyPair::try_from_seed(&seed_box(&seed_bytes)).expect("keypair b should succeed");

    assert_eq!(
        kp_a.verification_key().as_bytes(),
        kp_b.verification_key().as_bytes(),
        "deterministic seed must yield identical verification keys",
    );
}

/// Signing with a fixed randomness seed is deterministic — the same
/// `(signing_key, message, context, signing_seed)` always yields the
/// same signature.
#[test]
fn ml_dsa_65_sign_with_seed_is_deterministic() {
    let kp_seed = seed_box(&[0x11_u8; 32]);
    let kp = MlDsa65KeyPair::try_from_seed(&kp_seed).expect("keypair should succeed");

    let signing_seed = seed_box(&[0x77_u8; 32]);
    let message = b"deterministic signing test";
    let context = b"";

    let sig_a = kp
        .signing_key()
        .try_sign_with_seed(message, context, &signing_seed)
        .expect("sign a should succeed");
    let sig_b = kp
        .signing_key()
        .try_sign_with_seed(message, context, &signing_seed)
        .expect("sign b should succeed");

    assert_eq!(
        sig_a.as_bytes(),
        sig_b.as_bytes(),
        "fixed signing seed must yield identical signatures",
    );
}

/// Verifying a tampered message under an authentic signature fails.
#[test]
fn ml_dsa_65_verify_rejects_tampered_message() {
    use pulsar_kernel::error::Error;

    let kp =
        MlDsa65KeyPair::try_from_seed(&seed_box(&[0x55_u8; 32])).expect("keypair should succeed");

    let signature = kp
        .signing_key()
        .try_sign(b"original message", b"")
        .expect("sign should succeed");

    match kp
        .verification_key()
        .verify(b"tampered message", b"", &signature)
    {
        Err(Error::SignatureVerifyFailed) => {} // expected
        other => panic!("expected SignatureVerifyFailed for tampered message; got {other:?}"),
    }
}

/// Verifying a tampered signature fails — flipping a single bit
/// invalidates the signature.
#[test]
fn ml_dsa_65_verify_rejects_tampered_signature() {
    use pulsar_kernel::error::Error;

    let kp =
        MlDsa65KeyPair::try_from_seed(&seed_box(&[0x66_u8; 32])).expect("keypair should succeed");

    let message = b"signature-tamper test";
    let signature = kp
        .signing_key()
        .try_sign(message, b"")
        .expect("sign should succeed");

    let mut tampered_bytes = *signature.as_bytes();
    tampered_bytes[0] ^= 0x01;
    let tampered = MlDsa65Signature::from_bytes(tampered_bytes);

    match kp.verification_key().verify(message, b"", &tampered) {
        Err(Error::SignatureVerifyFailed) => {} // expected
        other => panic!("expected SignatureVerifyFailed for tampered signature; got {other:?}"),
    }
}

/// FIPS 204 § 5.2 domain separation — verifying with a different
/// context than was used at signing time fails. Even authentic
/// signatures only verify when sign + verify agree on the context.
#[test]
fn ml_dsa_65_verify_rejects_wrong_context() {
    use pulsar_kernel::error::Error;

    let kp =
        MlDsa65KeyPair::try_from_seed(&seed_box(&[0x77_u8; 32])).expect("keypair should succeed");

    let message = b"context-binding test";
    let signature = kp
        .signing_key()
        .try_sign(message, b"protocol-A")
        .expect("sign should succeed");

    match kp
        .verification_key()
        .verify(message, b"protocol-B", &signature)
    {
        Err(Error::SignatureVerifyFailed) => {} // expected
        other => panic!("expected SignatureVerifyFailed for context mismatch; got {other:?}"),
    }
}

/// Verifying under a wrong (unrelated) verification key fails.
#[test]
fn ml_dsa_65_verify_rejects_wrong_verification_key() {
    use pulsar_kernel::error::Error;

    let kp_a =
        MlDsa65KeyPair::try_from_seed(&seed_box(&[0xA1_u8; 32])).expect("kp a should succeed");
    let kp_b =
        MlDsa65KeyPair::try_from_seed(&seed_box(&[0xB2_u8; 32])).expect("kp b should succeed");

    let message = b"cross-key test";
    let signature_a = kp_a
        .signing_key()
        .try_sign(message, b"")
        .expect("sign a should succeed");

    match kp_b.verification_key().verify(message, b"", &signature_a) {
        Err(Error::SignatureVerifyFailed) => {} // expected
        other => panic!("expected SignatureVerifyFailed for wrong key; got {other:?}"),
    }
}

/// Signing rejects context strings exceeding the FIPS 204 § 5.2
/// 255-byte maximum.
#[test]
fn ml_dsa_65_sign_rejects_oversize_context() {
    use pulsar_kernel::error::Error;

    let kp =
        MlDsa65KeyPair::try_from_seed(&seed_box(&[0x11_u8; 32])).expect("keypair should succeed");

    let oversize = vec![0_u8; 256];
    match kp.signing_key().try_sign(b"any message", &oversize) {
        Err(Error::InputTooLong {
            actual: 256,
            max: 255,
        }) => {} // expected
        other => panic!("expected InputTooLong; got {other:?}"),
    }
}

/// Verifying rejects context strings exceeding the FIPS 204 § 5.2
/// 255-byte maximum.
#[test]
fn ml_dsa_65_verify_rejects_oversize_context() {
    use pulsar_kernel::error::Error;

    let kp =
        MlDsa65KeyPair::try_from_seed(&seed_box(&[0x22_u8; 32])).expect("keypair should succeed");
    let signature = kp
        .signing_key()
        .try_sign(b"msg", b"")
        .expect("sign should succeed");

    let oversize = vec![0_u8; 256];
    match kp.verification_key().verify(b"msg", &oversize, &signature) {
        Err(Error::InputTooLong {
            actual: 256,
            max: 255,
        }) => {} // expected
        other => panic!("expected InputTooLong; got {other:?}"),
    }
}

/// Length-validation paths surface the right `Error::InvalidKeyLength`.
#[test]
fn ml_dsa_65_length_validation_errors() {
    use pulsar_kernel::error::Error;

    // Wrong-length keygen seed (31/33 vs 32)
    let too_short = seed_box(&[0_u8; 31]);
    match MlDsa65KeyPair::try_from_seed(&too_short) {
        Err(Error::InvalidKeyLength {
            expected: 32,
            actual: 31,
        }) => {}
        other => panic!("expected InvalidKeyLength {{ 32, 31 }}; got {other:?}"),
    }

    // Wrong-length signing key (4031 vs 4032)
    let bad_sk = seed_box(&[0_u8; 4031]);
    match MlDsa65SigningKey::from_bytes(bad_sk) {
        Err(Error::InvalidKeyLength {
            expected: 4032,
            actual: 4031,
        }) => {}
        other => panic!("expected InvalidKeyLength {{ 4032, 4031 }}; got {other:?}"),
    }

    // Wrong-length signing randomness (31 vs 32)
    let kp = MlDsa65KeyPair::try_from_seed(&seed_box(&[0_u8; 32])).expect("keypair should succeed");
    let bad_seed = seed_box(&[0_u8; 31]);
    match kp.signing_key().try_sign_with_seed(b"msg", b"", &bad_seed) {
        Err(Error::InvalidKeyLength {
            expected: 32,
            actual: 31,
        }) => {}
        other => panic!("expected InvalidKeyLength; got {other:?}"),
    }
}

/// `Debug` impls do not leak signing-key bytes.
#[test]
fn ml_dsa_65_debug_redacts_signing_key() {
    let kp =
        MlDsa65KeyPair::try_from_seed(&seed_box(&[0xAB_u8; 32])).expect("keypair should succeed");

    let signing_debug = format!("{:?}", kp.signing_key());
    assert!(signing_debug.contains("MlDsa65SigningKey"));
    assert!(
        signing_debug.contains(".."),
        "signing-key Debug must use finish_non_exhaustive, got: {signing_debug}",
    );

    let vk_debug = format!("{:?}", kp.verification_key());
    assert!(vk_debug.contains("MlDsa65VerificationKey"));
    assert!(vk_debug.contains("bytes"));
}
