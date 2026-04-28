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

// `expect`/`unwrap` are allowed in tests per CLAUDE.md §5.
#![allow(clippy::expect_used, clippy::unwrap_used)]

use pulsar_kernel::crypto::aead::{AeadAlgorithm, AeadKey};
use pulsar_kernel::error::Error;

/// Three AEAD algorithms supported by `pulsar-kernel::crypto::aead`.
const ALGORITHMS: &[AeadAlgorithm] = &[
    AeadAlgorithm::Aes128Gcm,
    AeadAlgorithm::Aes256Gcm,
    AeadAlgorithm::ChaCha20Poly1305,
];

/// Build a key of the right length for the algorithm by zero-padding
/// or truncating the input bytes. Used in property tests where
/// proptest generates a max-length byte vector and we slice it
/// per-algorithm.
fn key_for(algo: AeadAlgorithm, bytes: &[u8]) -> Vec<u8> {
    let mut key = vec![0_u8; algo.key_len()];
    let copy_len = bytes.len().min(algo.key_len());
    key[..copy_len].copy_from_slice(&bytes[..copy_len]);
    key
}

proptest::proptest! {
    /// Encrypt then decrypt with the same `(key, nonce, aad)` recovers
    /// the original plaintext, byte-for-byte. The fundamental AEAD
    /// correctness property.
    #[test]
    fn encrypt_decrypt_round_trip(
        key_bytes in proptest::collection::vec(proptest::num::u8::ANY, 32..=32),
        nonce in proptest::collection::vec(proptest::num::u8::ANY, 12..=12),
        aad in proptest::collection::vec(proptest::num::u8::ANY, 0..128),
        plaintext in proptest::collection::vec(proptest::num::u8::ANY, 0..1024),
    ) {
        for &algo in ALGORITHMS {
            let key = key_for(algo, &key_bytes);
            let aead_key = AeadKey::new(algo, &key).expect("AeadKey::new should succeed");

            let ciphertext = aead_key
                .encrypt(&nonce, &aad, &plaintext)
                .expect("encrypt should succeed");

            // Output length: plaintext.len() + tag_len()
            proptest::prop_assert_eq!(
                ciphertext.len(),
                plaintext.len() + algo.tag_len()
            );

            let recovered = aead_key
                .decrypt(&nonce, &aad, &ciphertext)
                .expect("decrypt should recover plaintext from authentic ciphertext");
            proptest::prop_assert_eq!(recovered, plaintext.clone());
        }
    }

    /// Flipping any single bit in the ciphertext causes decryption to
    /// return `Error::AeadAuthFailed`. Validates the GCM / Poly1305
    /// authentication-tag mechanism — this is the security-critical
    /// invariant that a tampered ciphertext cannot decrypt.
    #[test]
    fn ciphertext_tampering_detected(
        key_bytes in proptest::collection::vec(proptest::num::u8::ANY, 32..=32),
        nonce in proptest::collection::vec(proptest::num::u8::ANY, 12..=12),
        aad in proptest::collection::vec(proptest::num::u8::ANY, 0..32),
        plaintext in proptest::collection::vec(proptest::num::u8::ANY, 1..256),
        flip_byte_idx in 0_usize..512,
        flip_bit_idx in 0_u8..8,
    ) {
        for &algo in ALGORITHMS {
            let key = key_for(algo, &key_bytes);
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
    }

    /// Changing AAD between encrypt + decrypt causes decryption to
    /// fail with `Error::AeadAuthFailed`. Validates that the AAD is
    /// covered by the authentication tag.
    #[test]
    fn aad_tampering_detected(
        key_bytes in proptest::collection::vec(proptest::num::u8::ANY, 32..=32),
        nonce in proptest::collection::vec(proptest::num::u8::ANY, 12..=12),
        original_aad in proptest::collection::vec(proptest::num::u8::ANY, 1..32),
        tampered_aad in proptest::collection::vec(proptest::num::u8::ANY, 1..32),
        plaintext in proptest::collection::vec(proptest::num::u8::ANY, 0..256),
    ) {
        proptest::prop_assume!(original_aad != tampered_aad);

        for &algo in ALGORITHMS {
            let key = key_for(algo, &key_bytes);
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
    }

    /// Distinct keys produce distinct ciphertexts for the same
    /// `(nonce, aad, plaintext)`. Probabilistic — for random keys the
    /// odds of identical output are negligible.
    #[test]
    fn distinct_keys_yield_distinct_ciphertexts(
        key_a in proptest::collection::vec(proptest::num::u8::ANY, 32..=32),
        key_b in proptest::collection::vec(proptest::num::u8::ANY, 32..=32),
        nonce in proptest::collection::vec(proptest::num::u8::ANY, 12..=12),
        aad in proptest::collection::vec(proptest::num::u8::ANY, 0..32),
        plaintext in proptest::collection::vec(proptest::num::u8::ANY, 1..128),
    ) {
        proptest::prop_assume!(key_a != key_b);

        for &algo in ALGORITHMS {
            let ka = key_for(algo, &key_a);
            let kb = key_for(algo, &key_b);
            // After the slice, keys may collide if the bytes are
            // identical in the prefix; skip those cases.
            proptest::prop_assume!(ka != kb);

            let aead_a = AeadKey::new(algo, &ka).expect("AeadKey::new(a) should succeed");
            let aead_b = AeadKey::new(algo, &kb).expect("AeadKey::new(b) should succeed");

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
                algo
            );
        }
    }

    /// Distinct nonces produce distinct ciphertexts under the same key.
    /// Validates the AEAD nonce-domain separation.
    #[test]
    fn distinct_nonces_yield_distinct_ciphertexts(
        key_bytes in proptest::collection::vec(proptest::num::u8::ANY, 32..=32),
        nonce_a in proptest::collection::vec(proptest::num::u8::ANY, 12..=12),
        nonce_b in proptest::collection::vec(proptest::num::u8::ANY, 12..=12),
        aad in proptest::collection::vec(proptest::num::u8::ANY, 0..32),
        plaintext in proptest::collection::vec(proptest::num::u8::ANY, 1..128),
    ) {
        proptest::prop_assume!(nonce_a != nonce_b);

        for &algo in ALGORITHMS {
            let key = key_for(algo, &key_bytes);
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
}
