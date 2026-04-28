//! Known-answer tests (KATs) for `pulsar_kernel::crypto::aead`.
//!
//! Test vectors sourced from the canonical specifications:
//!
//! - **AES-GCM** — NIST SP 800-38D Annex B (Test Cases 1, 2, 7)
//! - **ChaCha20-Poly1305** — RFC 8439 § 2.8.2
//!
//! Each test exercises the encrypt + decrypt round-trip + verifies the
//! reference ciphertext+tag byte-for-byte. A regression in either
//! direction fails the test loudly. Per plan Section XVII.19, KATs are
//! the smoke + integration layer of the testing hierarchy. Property
//! tests live alongside in `tests/aead_property.rs`.

// `expect`/`unwrap` are allowed in tests per CLAUDE.md §5 — panic on
// test-vector decode failure or unexpected primitive errors is the
// idiomatic way to surface a regression. `panic!` is allowed for the
// length-validation tests where we want to fail loudly with a custom
// message rather than match-asserting via `assert!`.
#![allow(clippy::expect_used, clippy::unwrap_used, clippy::panic)]

use pulsar_kernel::crypto::aead::{AeadAlgorithm, AeadKey};
use secrecy::SecretBox;

/// Helper: wrap raw key bytes in a `SecretBox<[u8]>`. Tests need to
/// produce the secret-wrapped form from hex-decoded bytes; this keeps
/// the boilerplate to one function call.
fn secret_key(bytes: Vec<u8>) -> SecretBox<[u8]> {
    SecretBox::new(bytes.into_boxed_slice())
}

/// Helper: assert encrypt(key, nonce, aad, plaintext) produces the
/// expected ciphertext+tag bytes; assert decrypt round-trips back via
/// both [`AeadKey::decrypt`] (joined input) and
/// [`AeadKey::decrypt_separate`] (split input).
///
/// Seven parameters (key + nonce + aad + plaintext + expected ct +
/// expected tag + algo) are intentional — each test vector is a tuple
/// of those seven canonical inputs/outputs.
#[track_caller]
#[allow(clippy::too_many_arguments)]
fn assert_kat(
    algo: AeadAlgorithm,
    key_hex: &str,
    nonce_hex: &str,
    aad_hex: &str,
    plaintext_hex: &str,
    expected_ciphertext_hex: &str,
    expected_tag_hex: &str,
) {
    let key = secret_key(hex::decode(key_hex).expect("key hex must be valid"));
    let nonce = hex::decode(nonce_hex).expect("nonce hex must be valid");
    let aad = hex::decode(aad_hex).expect("aad hex must be valid");
    let plaintext = hex::decode(plaintext_hex).expect("plaintext hex must be valid");
    let expected_ciphertext =
        hex::decode(expected_ciphertext_hex).expect("expected ciphertext hex must be valid");
    let expected_tag = hex::decode(expected_tag_hex).expect("expected tag hex must be valid");

    let aead_key = AeadKey::new(algo, &key).expect("AeadKey::new should succeed for valid key");

    // Path A — agile encrypt (allocates Vec).
    let actual_output = aead_key
        .encrypt(&nonce, &aad, &plaintext)
        .expect("encrypt should succeed");
    let (actual_ciphertext, actual_tag) = actual_output.split_at(plaintext.len());

    assert_eq!(
        actual_ciphertext,
        expected_ciphertext.as_slice(),
        "{algo:?} ciphertext mismatch: expected {expected_ciphertext_hex}, got {}",
        hex::encode(actual_ciphertext),
    );
    assert_eq!(
        actual_tag,
        expected_tag.as_slice(),
        "{algo:?} tag mismatch: expected {expected_tag_hex}, got {}",
        hex::encode(actual_tag),
    );

    // Path B — encrypt_into (no Vec alloc).
    let mut into_output = vec![0_u8; plaintext.len() + algo.tag_len()];
    aead_key
        .encrypt_into(&nonce, &aad, &plaintext, &mut into_output)
        .expect("encrypt_into should succeed");
    assert_eq!(
        into_output, actual_output,
        "{algo:?} encrypt_into output must match encrypt output",
    );

    // Path C — decrypt (joined input) round-trip.
    let recovered_joined = aead_key
        .decrypt(&nonce, &aad, &actual_output)
        .expect("decrypt should succeed on authentic ciphertext");
    assert_eq!(
        recovered_joined, plaintext,
        "{algo:?} decrypt round-trip mismatch",
    );

    // Path D — decrypt_separate (split input) round-trip.
    let recovered_separate = aead_key
        .decrypt_separate(&nonce, &aad, actual_ciphertext, actual_tag)
        .expect("decrypt_separate should succeed on authentic ciphertext");
    assert_eq!(
        recovered_separate, plaintext,
        "{algo:?} decrypt_separate round-trip mismatch",
    );
}

// ─── NIST SP 800-38D Annex B AES-GCM ──────────────────────────────────

/// Test Case 1 — AES-128-GCM with empty key (all zeros), empty IV
/// (all zeros), empty plaintext, empty AAD. Tests the GCM tag
/// computation in the degenerate case.
#[test]
fn aes128_gcm_test_case_1_nist_sp_800_38d() {
    assert_kat(
        AeadAlgorithm::Aes128Gcm,
        "00000000000000000000000000000000",
        "000000000000000000000000",
        "",
        "",
        "",
        "58e2fccefa7e3061367f1d57a4e7455a",
    );
}

/// Test Case 2 — AES-128-GCM with empty key, empty IV, 16-byte
/// zero plaintext, empty AAD. Tests the GCM block-cipher mode on a
/// single block of plaintext.
#[test]
fn aes128_gcm_test_case_2_nist_sp_800_38d() {
    assert_kat(
        AeadAlgorithm::Aes128Gcm,
        "00000000000000000000000000000000",
        "000000000000000000000000",
        "",
        "00000000000000000000000000000000",
        "0388dace60b6a392f328c2b971b2fe78",
        "ab6e47d42cec13bdf53a67b21257bddf",
    );
}

/// Test Case 7 — AES-256-GCM with empty key, empty IV, empty
/// plaintext, empty AAD. Tests the GCM tag computation on the AES-256
/// path (different key schedule from AES-128).
#[test]
fn aes256_gcm_test_case_7_nist_sp_800_38d() {
    assert_kat(
        AeadAlgorithm::Aes256Gcm,
        "0000000000000000000000000000000000000000000000000000000000000000",
        "000000000000000000000000",
        "",
        "",
        "",
        "530f8afbc74536b9a963b4f1c4cb738b",
    );
}

// ─── RFC 8439 ChaCha20-Poly1305 ──────────────────────────────────────

/// RFC 8439 § 2.8.2 example — ChaCha20-Poly1305 with the canonical
/// "Ladies and Gentlemen" plaintext. Tests the ChaCha20 stream-cipher
/// path + Poly1305 MAC over a non-trivial 114-byte plaintext + 12-byte
/// AAD.
#[test]
fn chacha20_poly1305_rfc_8439_example() {
    let plaintext = b"Ladies and Gentlemen of the class of '99: \
                      If I could offer you only one tip for the \
                      future, sunscreen would be it.";

    assert_kat(
        AeadAlgorithm::ChaCha20Poly1305,
        "808182838485868788898a8b8c8d8e8f909192939495969798999a9b9c9d9e9f",
        "070000004041424344454647",
        "50515253c0c1c2c3c4c5c6c7",
        &hex::encode(plaintext),
        "d31a8d34648e60db7b86afbc53ef7ec2a4aded51296e08fea9e2b5a736ee62d6\
         3dbea45e8ca9671282fafb69da92728b1a71de0a9e060b2905d6a5b67ecd3b36\
         92ddbd7f2d778b8c9803aee328091b58fab324e4fad675945585808b4831d7bc\
         3ff4def08e4b7a9de576d26586cec64b6116",
        "1ae10b594f09e26a7e902ecbd0600691",
    );
}

// ─── Length validation ───────────────────────────────────────────────

/// Construction with wrong-length key returns
/// `Error::InvalidKeyLength` — the safe wrapper enforces algorithm-
/// specific key length at the type-system / runtime boundary before
/// touching the FFI surface.
#[test]
fn aead_key_new_rejects_wrong_key_length() {
    use pulsar_kernel::error::Error;

    // AES-128-GCM expects 16-byte key
    let too_short = secret_key(vec![0_u8; 15]);
    let too_long = secret_key(vec![0_u8; 17]);

    match AeadKey::new(AeadAlgorithm::Aes128Gcm, &too_short) {
        Err(Error::InvalidKeyLength {
            expected: 16,
            actual: 15,
        }) => {}
        other => panic!("expected InvalidKeyLength {{ expected: 16, actual: 15 }}, got {other:?}"),
    }

    match AeadKey::new(AeadAlgorithm::Aes128Gcm, &too_long) {
        Err(Error::InvalidKeyLength {
            expected: 16,
            actual: 17,
        }) => {}
        other => panic!("expected InvalidKeyLength {{ expected: 16, actual: 17 }}, got {other:?}"),
    }
}

/// Encrypt with wrong-length nonce returns `Error::InvalidNonceLength`.
#[test]
fn aead_encrypt_rejects_wrong_nonce_length() {
    use pulsar_kernel::error::Error;

    let key = secret_key(vec![0_u8; 16]);
    let aead_key = AeadKey::new(AeadAlgorithm::Aes128Gcm, &key).expect("valid key");

    let too_short_nonce = vec![0_u8; 11];
    let too_long_nonce = vec![0_u8; 13];

    match aead_key.encrypt(&too_short_nonce, b"", b"hello") {
        Err(Error::InvalidNonceLength {
            expected: 12,
            actual: 11,
        }) => {}
        other => {
            panic!("expected InvalidNonceLength {{ expected: 12, actual: 11 }}, got {other:?}")
        }
    }

    match aead_key.encrypt(&too_long_nonce, b"", b"hello") {
        Err(Error::InvalidNonceLength {
            expected: 12,
            actual: 13,
        }) => {}
        other => {
            panic!("expected InvalidNonceLength {{ expected: 12, actual: 13 }}, got {other:?}")
        }
    }
}

/// Decrypt with ciphertext shorter than the tag length returns
/// `Error::InvalidOutputLength` (no tag to authenticate).
#[test]
fn aead_decrypt_rejects_truncated_input() {
    use pulsar_kernel::error::Error;

    let key = secret_key(vec![0_u8; 16]);
    let nonce = vec![0_u8; 12];
    let aead_key = AeadKey::new(AeadAlgorithm::Aes128Gcm, &key).expect("valid key");

    // Less than 16 bytes — can't even contain the tag.
    let truncated = vec![0_u8; 10];
    match aead_key.decrypt(&nonce, b"", &truncated) {
        Err(Error::InvalidOutputLength {
            expected: 16,
            actual: 10,
        }) => {}
        other => {
            panic!("expected InvalidOutputLength {{ expected: 16, actual: 10 }}, got {other:?}")
        }
    }
}

/// `encrypt_into` with wrong-length output buffer returns
/// `Error::InvalidOutputLength`.
#[test]
fn aead_encrypt_into_rejects_wrong_output_length() {
    use pulsar_kernel::error::Error;

    let key = secret_key(vec![0_u8; 16]);
    let nonce = vec![0_u8; 12];
    let aead_key = AeadKey::new(AeadAlgorithm::Aes128Gcm, &key).expect("valid key");

    let plaintext = b"hello";
    // Expected output length = 5 + 16 = 21 bytes
    let mut too_small = vec![0_u8; 20];
    let mut too_big = vec![0_u8; 22];

    match aead_key.encrypt_into(&nonce, b"", plaintext, &mut too_small) {
        Err(Error::InvalidOutputLength {
            expected: 21,
            actual: 20,
        }) => {}
        other => {
            panic!("expected InvalidOutputLength {{ expected: 21, actual: 20 }}, got {other:?}")
        }
    }

    match aead_key.encrypt_into(&nonce, b"", plaintext, &mut too_big) {
        Err(Error::InvalidOutputLength {
            expected: 21,
            actual: 22,
        }) => {}
        other => {
            panic!("expected InvalidOutputLength {{ expected: 21, actual: 22 }}, got {other:?}")
        }
    }
}

/// `decrypt_separate` with wrong-length tag returns
/// `Error::InvalidOutputLength`.
#[test]
fn aead_decrypt_separate_rejects_wrong_tag_length() {
    use pulsar_kernel::error::Error;

    let key = secret_key(vec![0_u8; 16]);
    let nonce = vec![0_u8; 12];
    let aead_key = AeadKey::new(AeadAlgorithm::Aes128Gcm, &key).expect("valid key");

    let ciphertext = b"abc";
    let too_short_tag = vec![0_u8; 15];
    let too_long_tag = vec![0_u8; 17];

    match aead_key.decrypt_separate(&nonce, b"", ciphertext, &too_short_tag) {
        Err(Error::InvalidOutputLength {
            expected: 16,
            actual: 15,
        }) => {}
        other => {
            panic!("expected InvalidOutputLength {{ expected: 16, actual: 15 }}, got {other:?}")
        }
    }

    match aead_key.decrypt_separate(&nonce, b"", ciphertext, &too_long_tag) {
        Err(Error::InvalidOutputLength {
            expected: 16,
            actual: 17,
        }) => {}
        other => {
            panic!("expected InvalidOutputLength {{ expected: 16, actual: 17 }}, got {other:?}")
        }
    }
}

/// AeadAlgorithm constants are correct and match HACL\* expectations.
#[test]
fn aead_algorithm_constants() {
    assert_eq!(AeadAlgorithm::Aes128Gcm.key_len(), 16);
    assert_eq!(AeadAlgorithm::Aes256Gcm.key_len(), 32);
    assert_eq!(AeadAlgorithm::ChaCha20Poly1305.key_len(), 32);

    // All three algorithms share 12-byte nonce + 16-byte tag per
    // Pulsar's uniform AEAD interface.
    for algo in [
        AeadAlgorithm::Aes128Gcm,
        AeadAlgorithm::Aes256Gcm,
        AeadAlgorithm::ChaCha20Poly1305,
    ] {
        assert_eq!(algo.nonce_len(), 12);
        assert_eq!(algo.tag_len(), 16);
    }
}

/// `AeadKey` Debug impl mentions the algorithm but does not leak the
/// FFI state pointer.
#[test]
fn aead_key_debug_redacts_state_pointer() {
    let key = secret_key(vec![0_u8; 32]);
    let aead_key = AeadKey::new(AeadAlgorithm::ChaCha20Poly1305, &key).expect("valid key");
    let formatted = format!("{aead_key:?}");
    assert!(
        formatted.contains("ChaCha20Poly1305"),
        "AeadKey Debug must mention algorithm; got: {formatted}",
    );
    assert!(
        !formatted.contains("0x"),
        "AeadKey Debug must not leak the state pointer; got: {formatted}",
    );
}

/// Large-input round-trip — exercises HACL\*'s SIMD paths over a
/// 1-MiB plaintext to catch any block-boundary edge cases that
/// 1024-byte property tests would miss.
#[test]
fn aead_large_input_round_trip() {
    let key = secret_key(vec![0xAA; 32]);
    let nonce = vec![0xBB; 12];
    let aad = vec![0xCC; 256];
    let plaintext: Vec<u8> = (0_u32..(1024 * 1024))
        .map(|i| u8::try_from(i & 0xFF).unwrap_or(0))
        .collect();

    for algo in [
        AeadAlgorithm::Aes128Gcm,
        AeadAlgorithm::Aes256Gcm,
        AeadAlgorithm::ChaCha20Poly1305,
    ] {
        let key = if algo.key_len() == 16 {
            secret_key(vec![0xAA; 16])
        } else {
            secret_key(vec![0xAA; 32])
        };
        // shadow `key` is fine; suppress the unused-binding lint via _ prefix
        let aead_key = AeadKey::new(algo, &key).expect("AeadKey::new should succeed");

        let ciphertext = aead_key
            .encrypt(&nonce, &aad, &plaintext)
            .expect("encrypt should succeed on 1 MiB plaintext");
        assert_eq!(ciphertext.len(), plaintext.len() + algo.tag_len());

        let recovered = aead_key
            .decrypt(&nonce, &aad, &ciphertext)
            .expect("decrypt should succeed on 1 MiB ciphertext");
        assert_eq!(recovered, plaintext, "{algo:?} 1 MiB round-trip mismatch");
    }
    // suppress unused warning for the outer `key` binding
    drop(key);
}
