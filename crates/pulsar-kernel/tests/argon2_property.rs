//! Property tests for `pulsar_kernel::crypto::argon2`.
//!
//! Argon2id is intentionally memory-hard, so property tests use the
//! minimum admissible parameters (m=8, t=1, p=1, output=8) to keep
//! per-case latency around ~1 ms. Default proptest configuration runs
//! 256 cases per property; with 5 properties × ~1 ms ≈ 1.3 s total
//! latency budget. Production callers should use [`Argon2idParams::default`]
//! (or stronger) — these test parameters are NOT secure for production
//! use.

#![allow(clippy::expect_used, clippy::unwrap_used)]

use proptest::prelude::*;
use pulsar_kernel::crypto::argon2::{Argon2idParams, derive_key, hash_password, verify_password};
use pulsar_kernel::error::Error;
use secrecy::SecretBox;

/// Minimum-admissible parameters used across every property test.
/// m=8 (8 KiB) is the floor for p=1; output=16 satisfies both the RFC
/// 9106 § 3.1 floor (≥ 4) and `password_hash::Output::MIN_LENGTH` (10
/// bytes — the lower bound the `argon2` crate's PHC encoder enforces).
const FAST_PARAMS: Argon2idParams = Argon2idParams::new(8, 1, 1, 16);

fn make_password(bytes: Vec<u8>) -> SecretBox<[u8]> {
    SecretBox::new(bytes.into_boxed_slice())
}

proptest::proptest! {
    #![proptest_config(ProptestConfig {
        // Argon2id is memory-hard — keep the case count modest to stay
        // under the test-suite latency budget. Per-property: 32 cases
        // × ~1ms ≈ 32 ms × 6 properties ≈ 200 ms total.
        cases: 32,
        ..ProptestConfig::default()
    })]

    /// `derive_key` is deterministic — same `(password, salt, params)`
    /// always yields the same output. Required for password-derived
    /// key reproducibility (e.g., key-encryption-key derivation from
    /// a stable password + per-record salt).
    #[test]
    fn derive_key_is_deterministic(
        password_bytes in proptest::collection::vec(any::<u8>(), 1..64),
        salt in proptest::collection::vec(any::<u8>(), 8..32),
    ) {
        let password = make_password(password_bytes);

        let mut output_a = [0_u8; 16];
        let mut output_b = [0_u8; 16];
        derive_key(&password, &salt, FAST_PARAMS, &mut output_a)
            .expect("derive_key a should succeed");
        derive_key(&password, &salt, FAST_PARAMS, &mut output_b)
            .expect("derive_key b should succeed");

        proptest::prop_assert_eq!(
            output_a,
            output_b,
            "Argon2id derive_key must be deterministic",
        );
    }

    /// Distinct passwords (with same salt + params) yield distinct
    /// outputs. Probabilistic — for random passwords ≥ 1 byte the
    /// chance of identical 8-byte outputs is ~2⁻⁶⁴.
    #[test]
    fn distinct_passwords_yield_distinct_outputs(
        password_a_bytes in proptest::collection::vec(any::<u8>(), 1..64),
        password_b_bytes in proptest::collection::vec(any::<u8>(), 1..64),
        salt in proptest::collection::vec(any::<u8>(), 8..32),
    ) {
        proptest::prop_assume!(password_a_bytes != password_b_bytes);

        let password_a = make_password(password_a_bytes);
        let password_b = make_password(password_b_bytes);

        let mut output_a = [0_u8; 16];
        let mut output_b = [0_u8; 16];
        derive_key(&password_a, &salt, FAST_PARAMS, &mut output_a).expect("a");
        derive_key(&password_b, &salt, FAST_PARAMS, &mut output_b).expect("b");

        proptest::prop_assert_ne!(
            output_a,
            output_b,
            "distinct passwords must yield distinct keys",
        );
    }

    /// Distinct salts (with same password + params) yield distinct
    /// outputs. Probabilistic.
    #[test]
    fn distinct_salts_yield_distinct_outputs(
        password_bytes in proptest::collection::vec(any::<u8>(), 1..32),
        salt_a in proptest::collection::vec(any::<u8>(), 8..32),
        salt_b in proptest::collection::vec(any::<u8>(), 8..32),
    ) {
        proptest::prop_assume!(salt_a != salt_b);

        let password = make_password(password_bytes);

        let mut output_a = [0_u8; 16];
        let mut output_b = [0_u8; 16];
        derive_key(&password, &salt_a, FAST_PARAMS, &mut output_a).expect("a");
        derive_key(&password, &salt_b, FAST_PARAMS, &mut output_b).expect("b");

        proptest::prop_assert_ne!(
            output_a,
            output_b,
            "distinct salts must yield distinct keys",
        );
    }

    /// `hash_password` + `verify_password` round-trip succeeds for any
    /// `(password, salt)` with default-strength parameters. The
    /// fundamental password-storage correctness property.
    #[test]
    fn hash_verify_round_trip(
        password_bytes in proptest::collection::vec(any::<u8>(), 1..32),
        salt in proptest::collection::vec(any::<u8>(), 8..32),
    ) {
        let password = make_password(password_bytes);
        let phc = hash_password(&password, &salt, FAST_PARAMS)
            .expect("hash_password should succeed");
        verify_password(&password, &phc)
            .expect("verify_password should accept authentic password");
    }

    /// `verify_password` rejects any password that differs from the
    /// originally-hashed one with `PasswordVerifyFailed`.
    #[test]
    fn verify_rejects_distinct_passwords(
        password_a_bytes in proptest::collection::vec(any::<u8>(), 1..32),
        password_b_bytes in proptest::collection::vec(any::<u8>(), 1..32),
        salt in proptest::collection::vec(any::<u8>(), 8..32),
    ) {
        proptest::prop_assume!(password_a_bytes != password_b_bytes);

        let password_a = make_password(password_a_bytes);
        let password_b = make_password(password_b_bytes);

        let phc = hash_password(&password_a, &salt, FAST_PARAMS)
            .expect("hash_password should succeed");

        match verify_password(&password_b, &phc) {
            Err(Error::PasswordVerifyFailed) => {} // expected
            other => proptest::prop_assert!(
                false,
                "wrong password must fail with PasswordVerifyFailed; got {other:?}",
            ),
        }
    }

    /// `hash_password` produces a PHC string with the standard
    /// argon2id structure: `$argon2id$v=19$m=...,t=...,p=...$<salt>$<hash>`.
    #[test]
    fn phc_string_has_canonical_structure(
        password_bytes in proptest::collection::vec(any::<u8>(), 1..32),
        salt in proptest::collection::vec(any::<u8>(), 8..32),
    ) {
        let password = make_password(password_bytes);
        let phc = hash_password(&password, &salt, FAST_PARAMS)
            .expect("hash_password should succeed");

        // Six dollar-separated fields: <empty>, argon2id, v=19, params, salt-b64, hash-b64
        let parts: Vec<&str> = phc.split('$').collect();
        proptest::prop_assert_eq!(parts.len(), 6);
        proptest::prop_assert_eq!(parts[0], "");
        proptest::prop_assert_eq!(parts[1], "argon2id");
        proptest::prop_assert_eq!(parts[2], "v=19");
        proptest::prop_assert!(parts[3].starts_with("m="));
        // Salt + hash are non-empty base64.
        proptest::prop_assert!(!parts[4].is_empty());
        proptest::prop_assert!(!parts[5].is_empty());
    }
}
