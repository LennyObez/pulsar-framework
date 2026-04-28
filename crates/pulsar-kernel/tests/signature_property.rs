//! Property tests for `pulsar_kernel::crypto::signature` (Ed25519).
//!
//! Per plan Section XVII.19 testing convention. Each property is run by
//! `proptest` against randomly-generated seeds + messages across the
//! full input range the FFI surface accepts.
//!
//! The properties below are the security-critical Ed25519 invariants:
//!   - Sign / verify round-trip with the same `(key, message)` succeeds
//!     for any well-formed seed + message.
//!   - Signing is deterministic per RFC 8032 § 5.1.6 — the same
//!     `(key, message)` pair always yields the same 64-byte signature.
//!   - Distinct messages under the same key produce distinct signatures
//!     (probabilistic — a regression that produced colliding signatures
//!     would indicate a serious FFI bug).
//!   - Distinct seeds yield distinct public keys (probabilistic).
//!   - A signature produced by key A does NOT verify under key B's
//!     public key — fundamental authenticity property.
//!   - Tampering any byte of the message after signing causes
//!     verification to return `Error::SignatureVerifyFailed`.
//!
//! These properties are necessary (though not sufficient) for the
//! signature-correctness contract Pulsar relies on at higher layers
//! (audit-chain Ed25519 signatures, AS-AT timestamping, attestation
//! envelopes, JWS-equivalent token signing).

// `expect`/`unwrap` are allowed in tests per CLAUDE.md §5.
#![allow(clippy::expect_used, clippy::unwrap_used)]

use proptest::prelude::*;
use pulsar_kernel::crypto::signature::Ed25519PrivateKey;
use pulsar_kernel::error::Error;
use secrecy::SecretBox;

/// Strategy producing a 32-byte Ed25519 seed as a raw `Vec<u8>`. Wrapping
/// into [`SecretBox<[u8]>`] is deferred to [`private_from_vec`] so the
/// raw bytes can also be compared for inequality in two-key properties.
fn seed_strategy() -> impl Strategy<Value = Vec<u8>> {
    proptest::collection::vec(any::<u8>(), 32..=32)
}

/// Construct an [`Ed25519PrivateKey`] from a 32-byte vector. Caller must
/// ensure the slice is exactly 32 bytes — the underlying constructor
/// returns `Error::InvalidKeyLength` otherwise.
fn private_from_vec(bytes: Vec<u8>) -> Ed25519PrivateKey {
    let secret = SecretBox::new(bytes.into_boxed_slice());
    Ed25519PrivateKey::from_bytes(secret).expect("32-byte seed should be accepted")
}

proptest::proptest! {
    /// Sign then verify under the derived public key recovers
    /// `Ok(())` for every `(seed, message)` pair. The fundamental
    /// Ed25519 correctness property (RFC 8032 § 5.1.6 + § 5.1.7).
    #[test]
    fn sign_verify_round_trip(
        seed in seed_strategy(),
        message in proptest::collection::vec(any::<u8>(), 0..1024),
    ) {
        let private = private_from_vec(seed);
        let public = private.public_key();
        let signature = private.sign(&message).expect("sign should succeed");
        public
            .verify(&message, &signature)
            .expect("verify should succeed on authentic signature");
    }

    /// Signing is deterministic — the same `(key, message)` pair always
    /// produces the same 64-byte signature regardless of how many times
    /// `sign` is invoked. Required by RFC 8032 § 5.1.6 and load-bearing
    /// for downstream invariants (audit-chain signatures must be
    /// reproducible across replays).
    #[test]
    fn signing_is_deterministic(
        seed in seed_strategy(),
        message in proptest::collection::vec(any::<u8>(), 0..512),
    ) {
        let private = private_from_vec(seed);
        let sig_first = private.sign(&message).expect("first sign should succeed");
        let sig_second = private.sign(&message).expect("second sign should succeed");
        proptest::prop_assert_eq!(
            sig_first.as_bytes(),
            sig_second.as_bytes(),
            "Ed25519 signatures must be deterministic per RFC 8032 § 5.1.6",
        );
    }

    /// Distinct messages under the same key produce distinct signatures.
    /// Probabilistic — Ed25519 signatures are 64 random-looking bytes, so
    /// for any pair of distinct messages the chance of identical output
    /// is negligible (~2⁻⁵¹².) A regression that produced colliding
    /// signatures would indicate a serious FFI bug.
    #[test]
    fn distinct_messages_yield_distinct_signatures(
        seed in seed_strategy(),
        msg_a in proptest::collection::vec(any::<u8>(), 1..256),
        msg_b in proptest::collection::vec(any::<u8>(), 1..256),
    ) {
        proptest::prop_assume!(msg_a != msg_b);
        let private = private_from_vec(seed);
        let sig_a = private.sign(&msg_a).expect("sign a should succeed");
        let sig_b = private.sign(&msg_b).expect("sign b should succeed");
        proptest::prop_assert_ne!(
            sig_a.as_bytes(),
            sig_b.as_bytes(),
            "distinct messages must produce distinct signatures",
        );
    }

    /// Distinct seeds yield distinct public keys (probabilistic).
    /// SHA-512 of a 32-byte random seed is collision-resistant to ~2⁻²⁵⁶
    /// so for random seeds the public keys differ with overwhelming
    /// probability.
    #[test]
    fn distinct_seeds_yield_distinct_public_keys(
        seed_a in seed_strategy(),
        seed_b in seed_strategy(),
    ) {
        proptest::prop_assume!(seed_a != seed_b);
        let private_a = private_from_vec(seed_a);
        let private_b = private_from_vec(seed_b);
        let pub_a = private_a.public_key();
        let pub_b = private_b.public_key();
        proptest::prop_assert_ne!(
            pub_a.as_bytes(),
            pub_b.as_bytes(),
            "distinct seeds must derive distinct public keys",
        );
    }

    /// Verifying a signature produced by key A under key B's public key
    /// returns `Err(SignatureVerifyFailed)` for any pair of distinct
    /// keys + any message. The fundamental authenticity property.
    #[test]
    fn verify_rejects_wrong_public_key(
        seed_a in seed_strategy(),
        seed_b in seed_strategy(),
        message in proptest::collection::vec(any::<u8>(), 0..256),
    ) {
        proptest::prop_assume!(seed_a != seed_b);
        let private_a = private_from_vec(seed_a);
        let private_b = private_from_vec(seed_b);
        let public_b = private_b.public_key();
        let signature_a = private_a.sign(&message).expect("sign a should succeed");
        match public_b.verify(&message, &signature_a) {
            Err(Error::SignatureVerifyFailed) => {} // expected
            other => proptest::prop_assert!(
                false,
                "verify under wrong public key must fail with SignatureVerifyFailed; got {other:?}",
            ),
        }
    }

    /// Flipping any single bit of the message after signing causes
    /// verification to return `Err(SignatureVerifyFailed)`. Validates
    /// the integrity property of Ed25519 — the signature commits to the
    /// exact message bytes via SHA-512 hashing per RFC 8032 § 5.1.6.
    #[test]
    fn verify_rejects_tampered_message(
        seed in seed_strategy(),
        message in proptest::collection::vec(any::<u8>(), 1..256),
        flip_byte_idx in 0_usize..512,
        flip_bit_idx in 0_u8..8,
    ) {
        let private = private_from_vec(seed);
        let public = private.public_key();
        let signature = private.sign(&message).expect("sign should succeed");

        // Move `message` into `tampered`; the original is unused after
        // signing, so cloning would be redundant per clippy.
        let mut tampered = message;
        let actual_idx = flip_byte_idx % tampered.len();
        tampered[actual_idx] ^= 1_u8 << flip_bit_idx;

        match public.verify(&tampered, &signature) {
            Err(Error::SignatureVerifyFailed) => {} // expected
            other => proptest::prop_assert!(
                false,
                "verify of tampered message must fail with SignatureVerifyFailed; got {other:?}",
            ),
        }
    }

    /// Flipping any single bit of the signature after producing it
    /// causes verification to return `Err(SignatureVerifyFailed)`.
    /// Validates that the (R, s) signature components are bound to the
    /// authentication outcome — a partial signature does not verify.
    #[test]
    fn verify_rejects_tampered_signature(
        seed in seed_strategy(),
        message in proptest::collection::vec(any::<u8>(), 0..256),
        flip_byte_idx in 0_usize..64,
        flip_bit_idx in 0_u8..8,
    ) {
        use pulsar_kernel::crypto::signature::Ed25519Signature;

        let private = private_from_vec(seed);
        let public = private.public_key();
        let signature = private.sign(&message).expect("sign should succeed");

        let mut tampered_bytes = *signature.as_bytes();
        tampered_bytes[flip_byte_idx % 64] ^= 1_u8 << flip_bit_idx;
        let tampered = Ed25519Signature::from_bytes(tampered_bytes);

        match public.verify(&message, &tampered) {
            Err(Error::SignatureVerifyFailed) => {} // expected
            other => proptest::prop_assert!(
                false,
                "verify of tampered signature must fail with SignatureVerifyFailed; got {other:?}",
            ),
        }
    }
}
