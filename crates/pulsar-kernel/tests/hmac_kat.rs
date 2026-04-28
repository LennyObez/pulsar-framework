//! Known-answer tests for `pulsar_kernel::crypto::hmac`.
//!
//! RFC 4231 § 4 reference vectors for HMAC-SHA-2-256/384/512 (Test
//! Cases 1, 2, 3). RFC 7693 does not specify KAT vectors for
//! HMAC-BLAKE2; the BLAKE2 algorithms are exercised via the property
//! tests in `hmac_property.rs` instead.

#![allow(clippy::expect_used, clippy::unwrap_used, clippy::panic)]

use pulsar_kernel::crypto::hmac::{HmacAlgorithm, HmacKey};
use secrecy::SecretBox;

fn key_from_hex(hex: &str) -> SecretBox<[u8]> {
    let bytes = hex::decode(hex).expect("valid hex");
    SecretBox::new(bytes.into_boxed_slice())
}

fn key_from_bytes(bytes: &[u8]) -> SecretBox<[u8]> {
    SecretBox::new(bytes.to_vec().into_boxed_slice())
}

fn assert_kat(algo: HmacAlgorithm, key: SecretBox<[u8]>, data: &[u8], expected_hex: &str) {
    let expected = hex::decode(expected_hex).expect("valid hex");
    let hmac_key = HmacKey::new(algo, key).expect("HmacKey::new should succeed");

    let computed = hmac_key.compute(data).expect("compute should succeed");
    assert_eq!(
        computed,
        expected.as_slice(),
        "HMAC tag mismatch for {algo:?}",
    );
    assert_eq!(computed.len(), algo.tag_len());

    hmac_key
        .verify(data, &expected)
        .expect("verify of authentic tag should succeed");
}

/// RFC 4231 § 4.2 — Test Case 1: key = 0x0b * 20, data = "Hi There".
#[test]
fn hmac_sha256_rfc_4231_test_1() {
    assert_kat(
        HmacAlgorithm::Sha256,
        key_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b"),
        b"Hi There",
        "b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7",
    );
}

#[test]
fn hmac_sha384_rfc_4231_test_1() {
    assert_kat(
        HmacAlgorithm::Sha384,
        key_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b"),
        b"Hi There",
        "afd03944d84895626b0825f4ab46907f15f9dadbe4101ec682aa034c7cebc59cfaea9ea9076ede7f4af152e8b2fa9cb6",
    );
}

#[test]
fn hmac_sha512_rfc_4231_test_1() {
    assert_kat(
        HmacAlgorithm::Sha512,
        key_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b"),
        b"Hi There",
        "87aa7cdea5ef619d4ff0b4241a1d6cb02379f4e2ce4ec2787ad0b30545e17cdedaa833b7d6b8a702038b274eaea3f4e4be9d914eeb61f1702e696c203a126854",
    );
}

/// RFC 4231 § 4.3 — Test Case 2: key shorter than tag length.
/// key = "Jefe", data = "what do ya want for nothing?".
#[test]
fn hmac_sha256_rfc_4231_test_2() {
    assert_kat(
        HmacAlgorithm::Sha256,
        key_from_bytes(b"Jefe"),
        b"what do ya want for nothing?",
        "5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843",
    );
}

#[test]
fn hmac_sha384_rfc_4231_test_2() {
    assert_kat(
        HmacAlgorithm::Sha384,
        key_from_bytes(b"Jefe"),
        b"what do ya want for nothing?",
        "af45d2e376484031617f78d2b58a6b1b9c7ef464f5a01b47e42ec3736322445e8e2240ca5e69e2c78b3239ecfab21649",
    );
}

#[test]
fn hmac_sha512_rfc_4231_test_2() {
    assert_kat(
        HmacAlgorithm::Sha512,
        key_from_bytes(b"Jefe"),
        b"what do ya want for nothing?",
        "164b7a7bfcf819e2e395fbe73b56e0a387bd64222e831fd610270cd7ea2505549758bf75c05a994a6d034f65f8f0e6fdcaeab1a34d4a6b4b636e070a38bce737",
    );
}

/// RFC 4231 § 4.4 — Test Case 3: 50 bytes of data with a 20-byte key
/// (full block authentication test).
#[test]
fn hmac_sha256_rfc_4231_test_3() {
    let data = vec![0xdd_u8; 50];
    assert_kat(
        HmacAlgorithm::Sha256,
        key_from_hex("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"),
        &data,
        "773ea91e36800e46854db8ebd09181a72959098b3ef8c122d9635514ced565fe",
    );
}

/// `verify` rejects a tampered tag with `MacVerifyFailed`.
#[test]
fn hmac_verify_rejects_tampered_tag() {
    use pulsar_kernel::error::Error;

    let key = key_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b");
    let hmac_key = HmacKey::new(HmacAlgorithm::Sha256, key).expect("HmacKey::new should succeed");

    let mut tag = hmac_key
        .compute(b"Hi There")
        .expect("compute should succeed");
    tag[0] ^= 0x01;

    match hmac_key.verify(b"Hi There", &tag) {
        Err(Error::MacVerifyFailed) => {} // expected
        other => panic!("expected MacVerifyFailed; got {other:?}"),
    }
}

/// `verify` rejects a tampered message with `MacVerifyFailed`.
#[test]
fn hmac_verify_rejects_tampered_message() {
    use pulsar_kernel::error::Error;

    let key = key_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b");
    let hmac_key = HmacKey::new(HmacAlgorithm::Sha256, key).expect("HmacKey::new should succeed");

    let tag = hmac_key
        .compute(b"Hi There")
        .expect("compute should succeed");

    match hmac_key.verify(b"Hi There!", &tag) {
        Err(Error::MacVerifyFailed) => {} // expected
        other => panic!("expected MacVerifyFailed; got {other:?}"),
    }
}

/// `verify` rejects a tag with the wrong length via `InvalidOutputLength`.
#[test]
fn hmac_verify_rejects_wrong_tag_length() {
    use pulsar_kernel::error::Error;

    let key = key_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b");
    let hmac_key = HmacKey::new(HmacAlgorithm::Sha256, key).expect("HmacKey::new should succeed");

    let truncated_tag = vec![0_u8; 31]; // 32-byte tag is correct length
    match hmac_key.verify(b"Hi There", &truncated_tag) {
        Err(Error::InvalidOutputLength {
            expected: 32,
            actual: 31,
        }) => {}
        other => panic!("expected InvalidOutputLength; got {other:?}"),
    }
}

/// `compute_into` writes only `tag_len()` bytes (rejects shorter buffers).
#[test]
fn hmac_compute_into_rejects_short_buffer() {
    use pulsar_kernel::error::Error;

    let key = key_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b");
    let hmac_key = HmacKey::new(HmacAlgorithm::Sha256, key).expect("HmacKey::new should succeed");

    let mut short = [0_u8; 31];
    match hmac_key.compute_into(b"Hi There", &mut short) {
        Err(Error::InvalidOutputLength {
            expected: 32,
            actual: 31,
        }) => {}
        other => panic!("expected InvalidOutputLength; got {other:?}"),
    }
}

/// `Debug` impl on `HmacKey` redacts both algorithm key bytes and length.
#[test]
fn hmac_key_debug_redacts_key_bytes() {
    let key = key_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b");
    let hmac_key = HmacKey::new(HmacAlgorithm::Sha256, key).expect("HmacKey::new should succeed");
    let debug = format!("{hmac_key:?}");
    assert!(
        !debug.contains("0b0b"),
        "HmacKey Debug must not leak key bytes; got: {debug}",
    );
    assert!(debug.contains("HmacKey"));
    assert!(debug.contains("Sha256"));
}

/// Algorithm constants (`tag_len`) match the underlying hash output length.
#[test]
fn hmac_algorithm_tag_lengths() {
    assert_eq!(HmacAlgorithm::Sha256.tag_len(), 32);
    assert_eq!(HmacAlgorithm::Sha384.tag_len(), 48);
    assert_eq!(HmacAlgorithm::Sha512.tag_len(), 64);
    assert_eq!(HmacAlgorithm::Blake2b512.tag_len(), 64);
    assert_eq!(HmacAlgorithm::Blake2s256.tag_len(), 32);
}
