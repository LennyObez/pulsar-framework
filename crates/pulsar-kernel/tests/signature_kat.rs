//! Known-answer tests for `pulsar_kernel::crypto::signature`.
//!
//! RFC 8032 § 7.1 Ed25519 reference vectors. Each test exercises the
//! full sign + verify round-trip plus byte-for-byte comparison against
//! the reference output.

#![allow(clippy::expect_used, clippy::unwrap_used, clippy::panic)]

use pulsar_kernel::crypto::signature::{Ed25519PrivateKey, Ed25519PublicKey, Ed25519Signature};
use secrecy::SecretBox;

fn private_from_hex(hex: &str) -> Ed25519PrivateKey {
    let bytes = hex::decode(hex).expect("valid hex");
    let secret = SecretBox::new(bytes.into_boxed_slice());
    Ed25519PrivateKey::from_bytes(secret).expect("valid 32-byte seed")
}

fn public_from_hex(hex: &str) -> Ed25519PublicKey {
    let bytes = hex::decode(hex).expect("valid hex");
    let mut out = [0_u8; 32];
    out.copy_from_slice(&bytes);
    Ed25519PublicKey::from_bytes(out)
}

fn sig_from_hex(hex: &str) -> Ed25519Signature {
    let bytes = hex::decode(hex).expect("valid hex");
    let mut out = [0_u8; 64];
    out.copy_from_slice(&bytes);
    Ed25519Signature::from_bytes(out)
}

/// RFC 8032 § 7.1 TEST 1 — empty message.
#[test]
fn ed25519_rfc_8032_test_1_empty_message() {
    let private =
        private_from_hex("9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60");
    let expected_public =
        public_from_hex("d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a");
    let expected_signature = sig_from_hex(
        "e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b",
    );

    // Public-key derivation
    let derived_public = private.public_key();
    assert_eq!(
        derived_public.as_bytes(),
        expected_public.as_bytes(),
        "Ed25519 public-key derivation mismatch",
    );

    // Sign
    let signature = private.sign(b"").expect("Ed25519 sign should succeed");
    assert_eq!(
        signature.as_bytes(),
        expected_signature.as_bytes(),
        "Ed25519 signature mismatch on empty message",
    );

    // Verify the produced signature
    expected_public
        .verify(b"", &signature)
        .expect("Ed25519 verify should succeed on authentic signature");

    // Verify the reference signature directly
    expected_public
        .verify(b"", &expected_signature)
        .expect("Ed25519 reference signature must verify");
}

/// RFC 8032 § 7.1 TEST 2 — single-byte message 0x72.
#[test]
fn ed25519_rfc_8032_test_2_one_byte_message() {
    let private =
        private_from_hex("4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb");
    let expected_public =
        public_from_hex("3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c");
    let message = &[0x72_u8];
    let expected_signature = sig_from_hex(
        "92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00",
    );

    let derived_public = private.public_key();
    assert_eq!(derived_public.as_bytes(), expected_public.as_bytes());

    let signature = private.sign(message).expect("Ed25519 sign should succeed");
    assert_eq!(signature.as_bytes(), expected_signature.as_bytes());

    expected_public
        .verify(message, &signature)
        .expect("verify of just-signed message should succeed");
}

/// Tampering the message causes verification to fail.
#[test]
fn ed25519_verify_rejects_tampered_message() {
    use pulsar_kernel::error::Error;

    let private =
        private_from_hex("9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60");
    let public = private.public_key();
    let signature = private.sign(b"hello").expect("sign should succeed");

    match public.verify(b"hellp", &signature) {
        Err(Error::SignatureVerifyFailed) => {} // expected
        other => panic!("expected SignatureVerifyFailed; got {other:?}"),
    }
}

/// Wrong-length seed → InvalidKeyLength.
#[test]
fn ed25519_private_key_rejects_wrong_seed_length() {
    use pulsar_kernel::error::Error;

    let too_short = SecretBox::new(vec![0_u8; 31].into_boxed_slice());
    let too_long = SecretBox::new(vec![0_u8; 33].into_boxed_slice());

    match Ed25519PrivateKey::from_bytes(too_short) {
        Err(Error::InvalidKeyLength {
            expected: 32,
            actual: 31,
        }) => {}
        other => panic!("expected InvalidKeyLength {{ expected: 32, actual: 31 }}; got {other:?}"),
    }

    match Ed25519PrivateKey::from_bytes(too_long) {
        Err(Error::InvalidKeyLength {
            expected: 32,
            actual: 33,
        }) => {}
        other => panic!("expected InvalidKeyLength {{ expected: 32, actual: 33 }}; got {other:?}"),
    }
}

/// Debug impls do not leak seed / signature bytes.
#[test]
fn ed25519_debug_impls_redact_secrets() {
    let private =
        private_from_hex("9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60");
    let signature = private.sign(b"x").expect("sign should succeed");

    let private_debug = format!("{private:?}");
    assert!(
        !private_debug.contains("9d61"),
        "private-key Debug must not leak seed bytes; got: {private_debug}",
    );

    let pub_debug = format!("{:?}", private.public_key());
    // Public key Debug shows a short hex prefix — that's expected;
    // public keys are not secret.
    assert!(pub_debug.contains("Ed25519PublicKey"));

    // Signature Debug shows a short hex prefix — signatures are not
    // secret per RFC 8032 (only the secret key is).
    let sig_debug = format!("{signature:?}");
    assert!(sig_debug.contains("Ed25519Signature"));
}
