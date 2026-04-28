//! Property tests for `pulsar_kernel::crypto::hkdf`.
//!
//! Per plan Section XVII.19 testing convention. Properties cover the
//! security-critical HKDF invariants: extract+expand decomposition
//! matches the combined helper, determinism, and probabilistic
//! distinctness across each input dimension (salt, IKM, info).
//!
//! Inputs span all five HMAC algorithms supported by HACL\* — the
//! BLAKE2 paths get coverage here since RFC 5869 lacks reference KAT
//! vectors for them.

#![allow(clippy::expect_used, clippy::unwrap_used)]

use proptest::prelude::*;
use pulsar_kernel::crypto::hkdf::{expand, extract, hkdf};
use pulsar_kernel::crypto::hmac::HmacAlgorithm;
use secrecy::{ExposeSecret, SecretBox};

const ALGORITHMS: &[HmacAlgorithm] = &[
    HmacAlgorithm::Sha256,
    HmacAlgorithm::Sha384,
    HmacAlgorithm::Sha512,
    HmacAlgorithm::Blake2b512,
    HmacAlgorithm::Blake2s256,
];

fn make_ikm(bytes: Vec<u8>) -> SecretBox<[u8]> {
    SecretBox::new(bytes.into_boxed_slice())
}

proptest::proptest! {
    /// Combined `hkdf(salt, ikm, info, length)` produces the same OKM
    /// as decomposed `extract` + `expand` calls. Validates the
    /// all-in-one helper does not introduce any divergence from the
    /// two-step API.
    #[test]
    fn combined_matches_decomposed(
        algo in proptest::sample::select(ALGORITHMS),
        salt in proptest::collection::vec(any::<u8>(), 0..64),
        ikm_bytes in proptest::collection::vec(any::<u8>(), 1..128),
        info in proptest::collection::vec(any::<u8>(), 0..64),
        length in 1_usize..256,
    ) {
        let ikm = make_ikm(ikm_bytes);
        let combined = hkdf(algo, &salt, &ikm, &info, length).expect("combined hkdf should succeed");

        let prk = extract(algo, &salt, &ikm).expect("extract should succeed");
        let decomposed = expand(algo, &prk, &info, length).expect("expand should succeed");

        proptest::prop_assert_eq!(
            combined.expose_secret(),
            decomposed.expose_secret(),
            "combined hkdf must equal extract+expand for {:?}",
            algo,
        );
    }

    /// HKDF is deterministic — same `(algo, salt, ikm, info, length)`
    /// always yields the same OKM. RFC 5869 § 2 makes this explicit
    /// via the construction definition (HMAC-based, deterministic).
    #[test]
    fn is_deterministic(
        algo in proptest::sample::select(ALGORITHMS),
        salt in proptest::collection::vec(any::<u8>(), 0..32),
        ikm_bytes in proptest::collection::vec(any::<u8>(), 1..64),
        info in proptest::collection::vec(any::<u8>(), 0..32),
        length in 1_usize..128,
    ) {
        let ikm = make_ikm(ikm_bytes);
        let okm_a = hkdf(algo, &salt, &ikm, &info, length).expect("hkdf a should succeed");
        let okm_b = hkdf(algo, &salt, &ikm, &info, length).expect("hkdf b should succeed");
        proptest::prop_assert_eq!(
            okm_a.expose_secret(),
            okm_b.expose_secret(),
            "HKDF must be deterministic",
        );
    }

    /// Distinct IKMs (with same salt + info + length) produce distinct
    /// OKMs. Probabilistic — for random IKMs the chance of identical
    /// OKMs is bounded by 2⁻⁸ᴸ where L is the requested output. Lower
    /// bound on `length` is 8 bytes (≈ 2⁻⁶⁴ collision probability,
    /// effectively zero across realistic test budgets) — short
    /// outputs (1–7 bytes) trip false positives at observable rates
    /// because `prop_assume!(ikm_a != ikm_b)` does not enforce
    /// distinct OKMs, only distinct inputs.
    #[test]
    fn distinct_ikm_yields_distinct_okm(
        algo in proptest::sample::select(ALGORITHMS),
        salt in proptest::collection::vec(any::<u8>(), 0..32),
        ikm_a_bytes in proptest::collection::vec(any::<u8>(), 1..64),
        ikm_b_bytes in proptest::collection::vec(any::<u8>(), 1..64),
        info in proptest::collection::vec(any::<u8>(), 0..32),
        length in 8_usize..64,
    ) {
        proptest::prop_assume!(ikm_a_bytes != ikm_b_bytes);

        let ikm_a = make_ikm(ikm_a_bytes);
        let ikm_b = make_ikm(ikm_b_bytes);
        let okm_a = hkdf(algo, &salt, &ikm_a, &info, length).expect("hkdf a should succeed");
        let okm_b = hkdf(algo, &salt, &ikm_b, &info, length).expect("hkdf b should succeed");

        proptest::prop_assert_ne!(
            okm_a.expose_secret(),
            okm_b.expose_secret(),
            "distinct IKMs must yield distinct OKMs",
        );
    }

    /// Distinct salts (with same IKM + info + length) produce distinct
    /// OKMs. The salt is mixed into the PRK via HMAC(salt, IKM), so
    /// any change in salt cascades into the OKM. Same 8-byte
    /// `length` floor as `distinct_ikm_yields_distinct_okm` to bound
    /// false positives at ~2⁻⁶⁴.
    #[test]
    fn distinct_salt_yields_distinct_okm(
        algo in proptest::sample::select(ALGORITHMS),
        salt_a in proptest::collection::vec(any::<u8>(), 1..32),
        salt_b in proptest::collection::vec(any::<u8>(), 1..32),
        ikm_bytes in proptest::collection::vec(any::<u8>(), 1..64),
        info in proptest::collection::vec(any::<u8>(), 0..32),
        length in 8_usize..64,
    ) {
        proptest::prop_assume!(salt_a != salt_b);

        let ikm = make_ikm(ikm_bytes);
        let okm_a = hkdf(algo, &salt_a, &ikm, &info, length).expect("hkdf a should succeed");
        let okm_b = hkdf(algo, &salt_b, &ikm, &info, length).expect("hkdf b should succeed");

        proptest::prop_assert_ne!(
            okm_a.expose_secret(),
            okm_b.expose_secret(),
            "distinct salts must yield distinct OKMs",
        );
    }

    /// Distinct infos (with same salt + IKM + length) produce distinct
    /// OKMs. The info string parameterises the expand step's domain
    /// separation per RFC 5869 § 3.2. Same 8-byte `length` floor as
    /// the IKM / salt distinctness properties.
    #[test]
    fn distinct_info_yields_distinct_okm(
        algo in proptest::sample::select(ALGORITHMS),
        salt in proptest::collection::vec(any::<u8>(), 0..32),
        ikm_bytes in proptest::collection::vec(any::<u8>(), 1..64),
        info_a in proptest::collection::vec(any::<u8>(), 1..32),
        info_b in proptest::collection::vec(any::<u8>(), 1..32),
        length in 8_usize..64,
    ) {
        proptest::prop_assume!(info_a != info_b);

        let ikm = make_ikm(ikm_bytes);
        let okm_a = hkdf(algo, &salt, &ikm, &info_a, length).expect("hkdf a should succeed");
        let okm_b = hkdf(algo, &salt, &ikm, &info_b, length).expect("hkdf b should succeed");

        proptest::prop_assert_ne!(
            okm_a.expose_secret(),
            okm_b.expose_secret(),
            "distinct infos must yield distinct OKMs",
        );
    }

    /// OKM length matches the requested `length` parameter exactly,
    /// for any admissible request size. Validates the FFI's
    /// counter-mode loop terminates at the right block boundary.
    #[test]
    fn okm_length_matches_request(
        algo in proptest::sample::select(ALGORITHMS),
        salt in proptest::collection::vec(any::<u8>(), 0..16),
        ikm_bytes in proptest::collection::vec(any::<u8>(), 1..32),
        info in proptest::collection::vec(any::<u8>(), 0..16),
        length in 1_usize..512,
    ) {
        let ikm = make_ikm(ikm_bytes);
        let okm = hkdf(algo, &salt, &ikm, &info, length).expect("hkdf should succeed");
        proptest::prop_assert_eq!(okm.expose_secret().len(), length);
    }
}
