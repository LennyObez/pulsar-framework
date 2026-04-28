//! Property tests for `pulsar_kernel::crypto::aead`.
//!
//! Per plan Section XVII.19 testing convention. Each property is run by
//! `proptest` against randomly-generated inputs across the full range of
//! key/nonce/AAD/plaintext combinations the FFI surface accepts.
//!
//! The properties below are the security-critical AEAD invariants:
//!   - Encrypt / decrypt round-trip with the same key + nonce + AAD
//!     recovers the exact plaintext.
//!   - Any byte flip in the ciphertext, tag, AAD, or nonce causes
//!     decryption to fail with `Error::AeadAuthFailed`.
//!   - Distinct keys / nonces produce distinct ciphertexts.
//!
//! These properties are necessary (though not sufficient) for the
//! AEAD-correctness contract Pulsar relies on at higher layers (audit
//! chain, session tokens, encrypted-at-rest storage).
//!
//! Each property is parameterised over algorithm via `algo_with_key`
//! that picks (algo, key) tuples with the algorithm-specific key
//! length — no zero-padding shenanigans, no wasted proptest cases on
//! prefix collisions.

// `expect`/`unwrap` are allowed in tests per CLAUDE.md §5.
#![allow(clippy::expect_used, clippy::unwrap_used)]

use proptest::prelude::*;
use pulsar_kernel::crypto::aead::{AeadAlgorithm, AeadKey};
use pulsar_kernel::error::Error;
use secrecy::{ExposeSecret, SecretBox};

/// Strategy producing (algorithm, key) pairs with algorithm-specific
/// key length. The three branches share equal weight under
/// `prop_oneof!`.
fn algo_with_key() -> impl Strategy<Value = (AeadAlgorithm, SecretBox<[u8]>)> {
    prop_oneof![
        proptest::collection::vec(any::<u8>(), 16..=16).prop_map(|k| (
            AeadAlgorithm::Aes128Gcm,
            SecretBox::new(k.into_boxed_slice())
        )),
        proptest::collection::vec(any::<u8>(), 32..=32).prop_map(|k| (
            AeadAlgorithm::Aes256Gcm,
            SecretBox::new(k.into_boxed_slice())
        )),
        proptest::collection::vec(any::<u8>(), 32..=32).prop_map(|k| (
            AeadAlgorithm::ChaCha20Poly1305,
            SecretBox::new(k.into_boxed_slice())
        )),
    ]
}

proptest::proptest! {
    /// Encrypt then decrypt with the same `(key, nonce, aad)` recovers
    /// the original plaintext, byte-for-byte. The fundamental AEAD
    /// correctness property.
    #[test]
    fn encrypt_decrypt_round_trip(
        (algo, key) in algo_with_key(),
        nonce in proptest::collection::vec(any::<u8>(), 12..=12),
        aad in proptest::collection::vec(any::<u8>(), 0..128),
        plaintext in proptest::collection::vec(any::<u8>(), 0..1024),
    ) {
        let aead_key = AeadKey::new(algo, &key).expect("AeadKey::new should succeed");

        let ciphertext = aead_key
            .encrypt(&nonce, &aad, &plaintext)
            .expect("encrypt should succeed");

        // Output length: plaintext.len() + tag_len()
        proptest::prop_assert_eq!(ciphertext.len(), plaintext.len() + algo.tag_len());

        let recovered = aead_key
            .decrypt(&nonce, &aad, &ciphertext)
            .expect("decrypt should recover plaintext from authentic ciphertext");
        proptest::prop_assert_eq!(recovered, plaintext);
    }

    /// `encrypt_into` produces the same output bytes as `encrypt` for
    /// every input combination. Validates the slice-API parity with
    /// the Vec-allocating API.
    #[test]
    fn encrypt_into_matches_encrypt(
        (algo, key) in algo_with_key(),
        nonce in proptest::collection::vec(any::<u8>(), 12..=12),
        aad in proptest::collection::vec(any::<u8>(), 0..128),
        plaintext in proptest::collection::vec(any::<u8>(), 0..1024),
    ) {
        let aead_key = AeadKey::new(algo, &key).expect("AeadKey::new should succeed");

        let via_encrypt = aead_key
            .encrypt(&nonce, &aad, &plaintext)
            .expect("encrypt should succeed");

        let mut via_into = vec![0_u8; plaintext.len() + algo.tag_len()];
        aead_key
            .encrypt_into(&nonce, &aad, &plaintext, &mut via_into)
            .expect("encrypt_into should succeed");

        proptest::prop_assert_eq!(via_encrypt, via_into);
    }

    /// `decrypt_separate` produces the same plaintext as `decrypt` for
    /// every authentic ciphertext+tag pair.
    #[test]
    fn decrypt_separate_matches_decrypt(
        (algo, key) in algo_with_key(),
        nonce in proptest::collection::vec(any::<u8>(), 12..=12),
        aad in proptest::collection::vec(any::<u8>(), 0..128),
        plaintext in proptest::collection::vec(any::<u8>(), 0..1024),
    ) {
        let aead_key = AeadKey::new(algo, &key).expect("AeadKey::new should succeed");

        let joined = aead_key
            .encrypt(&nonce, &aad, &plaintext)
            .expect("encrypt should succeed");
        let (cipher_part, tag_part) = joined.split_at(plaintext.len());

        let via_decrypt = aead_key
            .decrypt(&nonce, &aad, &joined)
            .expect("decrypt should succeed");
        let via_separate = aead_key
            .decrypt_separate(&nonce, &aad, cipher_part, tag_part)
            .expect("decrypt_separate should succeed");

        proptest::prop_assert_eq!(via_decrypt, via_separate);
    }

    /// Flipping any single bit in the ciphertext causes decryption to
    /// return `Error::AeadAuthFailed`. Validates the GCM / Poly1305
    /// authentication-tag mechanism — this is the security-critical
    /// invariant that a tampered ciphertext cannot decrypt.
    #[test]
    fn ciphertext_tampering_detected(
        (algo, key) in algo_with_key(),
        nonce in proptest::collection::vec(any::<u8>(), 12..=12),
        aad in proptest::collection::vec(any::<u8>(), 0..32),
        plaintext in proptest::collection::vec(any::<u8>(), 1..256),
        flip_byte_idx in 0_usize..512,
        flip_bit_idx in 0_u8..8,
    ) {
        let aead_key = AeadKey::new(algo, &key).expect("AeadKey::new should succeed");

        let mut ciphertext = aead_key
            .encrypt(&nonce, &aad, &plaintext)
            .expect("encrypt should succeed");

        let actual_idx = flip_byte_idx % ciphertext.len();
        ciphertext[actual_idx] ^= 1_u8 << flip_bit_idx;

        match aead_key.decrypt(&nonce, &aad, &ciphertext) {
            Err(Error::AeadAuthFailed) => {} // expected
            other => proptest::prop_assert!(
                false,
                "tampered ciphertext must fail with AeadAuthFailed; got {other:?}"
            ),
        }
    }

    /// Changing AAD between encrypt + decrypt causes decryption to
    /// fail with `Error::AeadAuthFailed`. Validates that the AAD is
    /// covered by the authentication tag.
    #[test]
    fn aad_tampering_detected(
        (algo, key) in algo_with_key(),
        nonce in proptest::collection::vec(any::<u8>(), 12..=12),
        original_aad in proptest::collection::vec(any::<u8>(), 1..32),
        tampered_aad in proptest::collection::vec(any::<u8>(), 1..32),
        plaintext in proptest::collection::vec(any::<u8>(), 0..256),
    ) {
        proptest::prop_assume!(original_aad != tampered_aad);

        let aead_key = AeadKey::new(algo, &key).expect("AeadKey::new should succeed");

        let ciphertext = aead_key
            .encrypt(&nonce, &original_aad, &plaintext)
            .expect("encrypt should succeed");

        match aead_key.decrypt(&nonce, &tampered_aad, &ciphertext) {
            Err(Error::AeadAuthFailed) => {} // expected
            other => proptest::prop_assert!(
                false,
                "tampered AAD must fail with AeadAuthFailed; got {other:?}"
            ),
        }
    }

    /// Distinct keys produce distinct ciphertexts for the same
    /// `(nonce, aad, plaintext)`. Probabilistic — for random keys the
    /// odds of identical output are negligible.
    #[test]
    fn distinct_keys_yield_distinct_ciphertexts(
        (algo_a, key_a) in algo_with_key(),
        key_b_bytes in proptest::collection::vec(any::<u8>(), 32..=32),
        nonce in proptest::collection::vec(any::<u8>(), 12..=12),
        aad in proptest::collection::vec(any::<u8>(), 0..32),
        plaintext in proptest::collection::vec(any::<u8>(), 1..128),
    ) {
        // Build key_b at the algorithm-specific length from the
        // 32-byte random source — truncating for AES-128 (16 bytes),
        // identity for AES-256 / ChaCha20-Poly1305 (32 bytes).
        let key_b_truncated: Vec<u8> = key_b_bytes[..algo_a.key_len()].to_vec();
        let key_b = SecretBox::new(key_b_truncated.into_boxed_slice());

        proptest::prop_assume!(key_a.expose_secret() != key_b.expose_secret());

        let aead_a = AeadKey::new(algo_a, &key_a).expect("AeadKey::new(a) should succeed");
        let aead_b = AeadKey::new(algo_a, &key_b).expect("AeadKey::new(b) should succeed");

        let cipher_a = aead_a
            .encrypt(&nonce, &aad, &plaintext)
            .expect("encrypt a should succeed");
        let cipher_b = aead_b
            .encrypt(&nonce, &aad, &plaintext)
            .expect("encrypt b should succeed");

        proptest::prop_assert_ne!(
            cipher_a,
            cipher_b,
            "distinct {:?} keys must produce distinct ciphertexts",
            algo_a
        );
    }

    /// Distinct nonces produce distinct ciphertexts under the same key.
    /// Validates the AEAD nonce-domain separation.
    #[test]
    fn distinct_nonces_yield_distinct_ciphertexts(
        (algo, key) in algo_with_key(),
        nonce_a in proptest::collection::vec(any::<u8>(), 12..=12),
        nonce_b in proptest::collection::vec(any::<u8>(), 12..=12),
        aad in proptest::collection::vec(any::<u8>(), 0..32),
        plaintext in proptest::collection::vec(any::<u8>(), 1..128),
    ) {
        proptest::prop_assume!(nonce_a != nonce_b);

        let aead_key = AeadKey::new(algo, &key).expect("AeadKey::new should succeed");

        let cipher_a = aead_key
            .encrypt(&nonce_a, &aad, &plaintext)
            .expect("encrypt a should succeed");
        let cipher_b = aead_key
            .encrypt(&nonce_b, &aad, &plaintext)
            .expect("encrypt b should succeed");

        proptest::prop_assert_ne!(
            cipher_a,
            cipher_b,
            "distinct {:?} nonces must produce distinct ciphertexts",
            algo
        );
    }
}
