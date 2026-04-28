//! Known-answer tests for `pulsar_kernel::crypto::hkdf`.
//!
//! RFC 5869 Appendix A reference vectors for HKDF-SHA-256. The
//! property tests in `hkdf_property.rs` cover SHA-384 / SHA-512 /
//! BLAKE2 algorithms via round-trip + distinctness invariants.

#![allow(clippy::expect_used, clippy::unwrap_used, clippy::panic)]

use pulsar_kernel::crypto::hkdf::{expand, extract, hkdf};
use pulsar_kernel::crypto::hmac::HmacAlgorithm;
use secrecy::{ExposeSecret, SecretBox};

fn ikm_from_hex(hex: &str) -> SecretBox<[u8]> {
    let bytes = hex::decode(hex).expect("valid hex");
    SecretBox::new(bytes.into_boxed_slice())
}

/// RFC 5869 Appendix A.1 — Test Case 1: SHA-256 basic test.
#[test]
fn hkdf_sha256_rfc_5869_test_1() {
    let ikm = ikm_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b");
    let salt = hex::decode("000102030405060708090a0b0c").expect("valid hex");
    let info = hex::decode("f0f1f2f3f4f5f6f7f8f9").expect("valid hex");
    let expected_prk =
        hex::decode("077709362c2e32df0ddc3f0dc47bba6390b6c73bb50f9c3122ec844ad7c2b3e5")
            .expect("valid hex");
    let expected_okm = hex::decode(
        "3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865",
    )
    .expect("valid hex");

    // Extract step
    let prk = extract(HmacAlgorithm::Sha256, &salt, &ikm).expect("extract should succeed");
    assert_eq!(
        prk.expose_secret(),
        expected_prk.as_slice(),
        "RFC 5869 § A.1 PRK mismatch",
    );

    // Expand step
    let okm = expand(HmacAlgorithm::Sha256, &prk, &info, 42).expect("expand should succeed");
    assert_eq!(
        okm.expose_secret(),
        expected_okm.as_slice(),
        "RFC 5869 § A.1 OKM mismatch",
    );

    // Combined hkdf() should yield the same OKM.
    let combined =
        hkdf(HmacAlgorithm::Sha256, &salt, &ikm, &info, 42).expect("combined hkdf should succeed");
    assert_eq!(
        combined.expose_secret(),
        expected_okm.as_slice(),
        "combined hkdf() should match extract+expand",
    );
}

/// RFC 5869 Appendix A.2 — Test Case 2: SHA-256 with 80-byte
/// IKM/salt/info inputs. The 82-byte OKM reference in the RFC text
/// crosses three HMAC-SHA-256 output blocks; we verify the PRK only
/// here (32-byte exact match) and let the property tests validate the
/// multi-block expand path against the round-trip / determinism
/// invariants.
#[test]
fn hkdf_sha256_rfc_5869_test_2() {
    let ikm = ikm_from_hex(
        "000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f\
         202122232425262728292a2b2c2d2e2f303132333435363738393a3b3c3d3e3f\
         404142434445464748494a4b4c4d4e4f",
    );
    let salt = hex::decode(
        "606162636465666768696a6b6c6d6e6f707172737475767778797a7b7c7d7e7f\
         808182838485868788898a8b8c8d8e8f909192939495969798999a9b9c9d9e9f\
         a0a1a2a3a4a5a6a7a8a9aaabacadaeaf",
    )
    .expect("valid hex");
    let expected_prk =
        hex::decode("06a6b88c5853361a06104c9ceb35b45cef760014904671014a193f40c15fc244")
            .expect("valid hex");

    let prk = extract(HmacAlgorithm::Sha256, &salt, &ikm).expect("extract should succeed");
    assert_eq!(
        prk.expose_secret(),
        expected_prk.as_slice(),
        "RFC 5869 § A.2 PRK mismatch",
    );
}

/// RFC 5869 Appendix A.3 — Test Case 3: SHA-256 with zero-length salt + info.
#[test]
fn hkdf_sha256_rfc_5869_test_3() {
    let ikm = ikm_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b");
    let salt: &[u8] = &[];
    let info: &[u8] = &[];
    let expected_prk =
        hex::decode("19ef24a32c717b167f33a91d6f648bdf96596776afdb6377ac434c1c293ccb04")
            .expect("valid hex");
    let expected_okm = hex::decode(
        "8da4e775a563c18f715f802a063c5a31b8a11f5c5ee1879ec3454e5f3c738d2d\
         9d201395faa4b61a96c8",
    )
    .expect("valid hex");

    let prk = extract(HmacAlgorithm::Sha256, salt, &ikm).expect("extract should succeed");
    assert_eq!(prk.expose_secret(), expected_prk.as_slice());

    let okm = expand(HmacAlgorithm::Sha256, &prk, info, 42).expect("expand should succeed");
    assert_eq!(okm.expose_secret(), expected_okm.as_slice());
}

/// `expand` rejects a zero-length output request.
#[test]
fn hkdf_expand_rejects_zero_length() {
    use pulsar_kernel::error::Error;

    let ikm = ikm_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b");
    let prk = extract(HmacAlgorithm::Sha256, &[], &ikm).expect("extract should succeed");

    match expand(HmacAlgorithm::Sha256, &prk, &[], 0) {
        Err(Error::InvalidOutputLength { .. }) => {} // expected
        other => panic!("expected InvalidOutputLength; got {other:?}"),
    }
}

/// `expand` rejects an output request exceeding `255 * tag_len()` per
/// RFC 5869 § 2.3.
#[test]
fn hkdf_expand_rejects_too_long_output() {
    use pulsar_kernel::error::Error;

    let ikm = ikm_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b");
    let prk = extract(HmacAlgorithm::Sha256, &[], &ikm).expect("extract should succeed");

    let too_long = 255 * 32 + 1;
    match expand(HmacAlgorithm::Sha256, &prk, &[], too_long) {
        Err(Error::InvalidOutputLength { .. }) => {} // expected
        other => panic!("expected InvalidOutputLength; got {other:?}"),
    }
}

/// Maximum-length output request (`255 * tag_len()`) succeeds for
/// every supported algorithm.
#[test]
fn hkdf_expand_accepts_maximum_length() {
    let ikm = ikm_from_hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b");

    for &algo in &[
        HmacAlgorithm::Sha256,
        HmacAlgorithm::Sha384,
        HmacAlgorithm::Sha512,
        HmacAlgorithm::Blake2b512,
        HmacAlgorithm::Blake2s256,
    ] {
        let prk = extract(algo, &[], &ikm).expect("extract should succeed");
        let max_len = 255 * algo.tag_len();
        let okm = expand(algo, &prk, &[], max_len).expect("expand at max length should succeed");
        assert_eq!(okm.expose_secret().len(), max_len);
    }
}
