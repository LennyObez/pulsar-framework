//! Known-answer tests for `pulsar_kernel::crypto::kem`.
//!
//! RFC 7748 § 6.1 X25519 reference vector. Tests the public-key
//! derivation + Diffie-Hellman shared secret round-trip in both
//! directions (Alice → Bob and Bob → Alice produce the same secret).

#![allow(clippy::expect_used, clippy::unwrap_used, clippy::panic)]

use pulsar_kernel::crypto::kem::{X25519PrivateKey, X25519PublicKey};
use secrecy::{ExposeSecret, SecretBox};

fn private_from_hex(hex: &str) -> X25519PrivateKey {
    let bytes = hex::decode(hex).expect("valid hex");
    let secret = SecretBox::new(bytes.into_boxed_slice());
    X25519PrivateKey::from_bytes(secret).expect("valid 32-byte scalar")
}

fn public_from_hex(hex: &str) -> X25519PublicKey {
    let bytes = hex::decode(hex).expect("valid hex");
    let mut out = [0_u8; 32];
    out.copy_from_slice(&bytes);
    X25519PublicKey::from_bytes(out)
}

/// RFC 7748 § 6.1 — Alice and Bob derive the same shared secret.
#[test]
fn x25519_rfc_7748_section_6_1_shared_secret() {
    let alice_private =
        private_from_hex("77076d0a7318a57d3c16c17251b26645df4c2f87ebc0992ab177fba51db92c2a");
    let alice_public_expected =
        public_from_hex("8520f0098930a754748b7ddcb43ef75a0dbf3a0d26381af4eba4a98eaa9b4e6a");
    let bob_private =
        private_from_hex("5dab087e624a8a4b79e17f8b83800ee66f3bb1292618b6fd1c2f8b27ff88e0eb");
    let bob_public_expected =
        public_from_hex("de9edb7d7b7dc1b4d35b61c2ece435373f8343c85b78674dadfc7e146f882b4f");
    let expected_shared =
        hex::decode("4a5d9d5ba4ce2de1728e3bf480350f25e07e21c947d19e3376f09b3c1e161742")
            .expect("valid hex");

    // Public-key derivation matches the RFC vector.
    let alice_public = alice_private.public_key();
    assert_eq!(alice_public.as_bytes(), alice_public_expected.as_bytes());
    let bob_public = bob_private.public_key();
    assert_eq!(bob_public.as_bytes(), bob_public_expected.as_bytes());

    // Alice and Bob compute the same shared secret.
    let alice_shared = alice_private
        .diffie_hellman(&bob_public)
        .expect("Alice DH should succeed");
    let bob_shared = bob_private
        .diffie_hellman(&alice_public)
        .expect("Bob DH should succeed");

    assert_eq!(
        alice_shared.expose_secret(),
        expected_shared.as_slice(),
        "Alice DH shared secret must match RFC 7748 § 6.1 vector",
    );
    assert_eq!(
        bob_shared.expose_secret(),
        expected_shared.as_slice(),
        "Bob DH shared secret must match RFC 7748 § 6.1 vector",
    );
}

/// HACL\*'s `EverCrypt_Curve25519_ecdh` rejects the documented
/// small-order point (all-zero u-coordinate) as `Error::InvalidPublicKey`.
#[test]
fn x25519_rejects_small_order_public_key() {
    use pulsar_kernel::error::Error;

    let private =
        private_from_hex("77076d0a7318a57d3c16c17251b26645df4c2f87ebc0992ab177fba51db92c2a");
    // u = 0 is the canonical small-order point — yields the
    // identity-element shared secret which HACL* rejects per the F*
    // postcondition.
    let small_order = X25519PublicKey::from_bytes([0_u8; 32]);

    match private.diffie_hellman(&small_order) {
        Err(Error::InvalidPublicKey) => {} // expected
        other => panic!("expected InvalidPublicKey; got {other:?}"),
    }
}

/// Wrong-length scalar → InvalidKeyLength.
#[test]
fn x25519_private_key_rejects_wrong_scalar_length() {
    use pulsar_kernel::error::Error;

    let too_short = SecretBox::new(vec![0_u8; 31].into_boxed_slice());
    let too_long = SecretBox::new(vec![0_u8; 33].into_boxed_slice());

    match X25519PrivateKey::from_bytes(too_short) {
        Err(Error::InvalidKeyLength {
            expected: 32,
            actual: 31,
        }) => {}
        other => panic!("expected InvalidKeyLength; got {other:?}"),
    }

    match X25519PrivateKey::from_bytes(too_long) {
        Err(Error::InvalidKeyLength {
            expected: 32,
            actual: 33,
        }) => {}
        other => panic!("expected InvalidKeyLength; got {other:?}"),
    }
}
