//! Property tests for `pulsar_kernel::crypto::kem` (X25519).
//!
//! Per plan Section XVII.19 testing convention. Each property is run by
//! `proptest` against randomly-generated 32-byte scalars across the
//! full input range the FFI surface accepts.
//!
//! The properties below are the security-critical X25519 invariants:
//!   - Public-key derivation is deterministic — same seed yields same
//!     public point (RFC 7748 § 6 + § 5 clamping is pure).
//!   - Distinct seeds yield distinct public keys (probabilistic).
//!   - Diffie-Hellman is symmetric — `Alice.dh(Bob.pub)` and
//!     `Bob.dh(Alice.pub)` produce byte-identical shared secrets. The
//!     fundamental DH correctness property (RFC 7748 § 6.1).
//!   - Distinct private-key pairs against the same peer produce
//!     distinct shared secrets (probabilistic).
//!
//! Public keys are always derived from random seeds via `public_key()`
//! rather than constructed from arbitrary bytes via `from_bytes` —
//! HACL\*'s scalar multiplication of the clamped seed against the
//! Curve25519 base point guarantees on-curve, non-small-order output.
//! Random 32-byte u-coordinates would otherwise hit the documented
//! small-order subgroup with negligible-but-nonzero probability and
//! would have to be filtered with `prop_assume!`.
//!
//! These properties are necessary (though not sufficient) for the
//! key-agreement-correctness contract Pulsar relies on at higher layers
//! (Noise transport handshakes, hybrid post-quantum KEM construction
//! per Phase 1.1.C, session-token X25519 derivation).

// `expect`/`unwrap` are allowed in tests per CLAUDE.md §5.
#![allow(clippy::expect_used, clippy::unwrap_used)]

use proptest::prelude::*;
use pulsar_kernel::crypto::kem::X25519PrivateKey;
use secrecy::{ExposeSecret, SecretBox};

/// Strategy producing a 32-byte X25519 scalar as a raw `Vec<u8>`. The
/// scalar is clamped per RFC 7748 § 5 internally by HACL\* before use,
/// so any 32-byte input is well-formed.
fn scalar_strategy() -> impl Strategy<Value = Vec<u8>> {
    proptest::collection::vec(any::<u8>(), 32..=32)
}

/// Construct an [`X25519PrivateKey`] from a 32-byte vector. Caller must
/// ensure the slice is exactly 32 bytes — the underlying constructor
/// returns `Error::InvalidKeyLength` otherwise.
fn private_from_vec(bytes: Vec<u8>) -> X25519PrivateKey {
    let secret = SecretBox::new(bytes.into_boxed_slice());
    X25519PrivateKey::from_bytes(secret).expect("32-byte scalar should be accepted")
}

proptest::proptest! {
    /// Public-key derivation is deterministic — calling `public_key()`
    /// twice on the same private key yields byte-identical output. The
    /// scalar multiplication of the clamped seed against the Curve25519
    /// base point is a pure function with no caller-visible state.
    #[test]
    fn public_key_derivation_is_deterministic(
        seed in scalar_strategy(),
    ) {
        let private = private_from_vec(seed);
        let pub_first = private.public_key();
        let pub_second = private.public_key();
        proptest::prop_assert_eq!(
            pub_first.as_bytes(),
            pub_second.as_bytes(),
            "X25519 public-key derivation must be deterministic",
        );
    }

    /// Distinct seeds yield distinct public keys (probabilistic). RFC
    /// 7748 § 5 clamping coalesces 2¹⁹ pre-clamp seeds onto each clamped
    /// scalar; for random 32-byte seeds the chance of two random inputs
    /// falling in the same equivalence class is ~2⁻²²⁹ — effectively
    /// zero for any realistic test budget.
    #[test]
    fn distinct_seeds_yield_distinct_public_keys(
        seed_a in scalar_strategy(),
        seed_b in scalar_strategy(),
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

    /// X25519 Diffie-Hellman is symmetric: `Alice.dh(Bob.pub)` and
    /// `Bob.dh(Alice.pub)` produce byte-identical shared secrets for
    /// any pair of seeds. The fundamental DH correctness property
    /// validated against RFC 7748 § 6.1's reference vector in
    /// `kem_kat.rs` and generalised here over random key pairs.
    #[test]
    fn diffie_hellman_is_symmetric(
        seed_a in scalar_strategy(),
        seed_b in scalar_strategy(),
    ) {
        let alice = private_from_vec(seed_a);
        let bob = private_from_vec(seed_b);
        let alice_public = alice.public_key();
        let bob_public = bob.public_key();

        let alice_shared = alice
            .diffie_hellman(&bob_public)
            .expect("Alice DH should succeed against derived peer key");
        let bob_shared = bob
            .diffie_hellman(&alice_public)
            .expect("Bob DH should succeed against derived peer key");

        proptest::prop_assert_eq!(
            alice_shared.expose_secret(),
            bob_shared.expose_secret(),
            "X25519 DH must be symmetric — both parties derive the same shared secret",
        );
    }

    /// Two distinct private keys talking to the same peer produce
    /// distinct shared secrets (probabilistic). A regression that
    /// produced colliding shared secrets across distinct private keys
    /// would indicate a serious FFI bug or scalar-multiplication
    /// regression.
    #[test]
    fn distinct_private_keys_yield_distinct_shared_secrets(
        seed_a in scalar_strategy(),
        seed_b in scalar_strategy(),
        peer_seed in scalar_strategy(),
    ) {
        proptest::prop_assume!(seed_a != seed_b);

        let alice = private_from_vec(seed_a);
        let bob = private_from_vec(seed_b);
        let peer = private_from_vec(peer_seed);
        let peer_public = peer.public_key();

        let alice_shared = alice
            .diffie_hellman(&peer_public)
            .expect("Alice DH should succeed");
        let bob_shared = bob
            .diffie_hellman(&peer_public)
            .expect("Bob DH should succeed");

        proptest::prop_assert_ne!(
            alice_shared.expose_secret(),
            bob_shared.expose_secret(),
            "distinct private keys must derive distinct shared secrets against the same peer",
        );
    }

    /// Two DH operations with the same `(private, peer)` pair produce
    /// byte-identical shared secrets — DH is a pure function of its
    /// inputs, no caller-visible state.
    #[test]
    fn diffie_hellman_is_deterministic(
        seed in scalar_strategy(),
        peer_seed in scalar_strategy(),
    ) {
        let private = private_from_vec(seed);
        let peer = private_from_vec(peer_seed);
        let peer_public = peer.public_key();

        let shared_first = private
            .diffie_hellman(&peer_public)
            .expect("first DH should succeed");
        let shared_second = private
            .diffie_hellman(&peer_public)
            .expect("second DH should succeed");

        proptest::prop_assert_eq!(
            shared_first.expose_secret(),
            shared_second.expose_secret(),
            "X25519 DH must be deterministic for fixed (private, peer) inputs",
        );
    }
}
