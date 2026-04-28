//! Known-answer tests (KATs) for `pulsar_kernel::crypto::hash`.
//!
//! Test vectors sourced from the canonical specifications:
//!
//! - **SHA-2** — FIPS 180-4 § B.1 (empty) + Appendix B.1 ("abc")
//! - **SHA-3** — FIPS 202 Appendix A1.1 + NIST CAVP
//! - **BLAKE2** — RFC 7693 Appendix A
//!
//! Each test executes both API surfaces (one-shot + streaming) and
//! verifies the output matches the reference vector byte-for-byte. A
//! single regression in either surface fails the test loudly.
//!
//! Per plan Section XVII.19, KATs are the smoke + integration layer of
//! the testing hierarchy. Property tests live alongside in
//! `tests/hash_property.rs`. Phase 1.1.D adds Creusot contracts on the
//! safe wrappers + a TLA+ specification for the key lifecycle states.

// `expect`/`unwrap` are allowed in tests per CLAUDE.md §5 — panic on
// test-vector decode failure or unexpected primitive errors is the
// idiomatic way to surface a regression.
#![allow(clippy::expect_used, clippy::unwrap_used)]

use pulsar_kernel::crypto::hash::{HashAlgorithm, Hasher, hash};

/// Helper: assert the one-shot API matches the expected hex digest.
#[track_caller]
fn assert_oneshot(algo: HashAlgorithm, input: &[u8], expected_hex: &str) {
    let actual = hash(algo, input).expect("one-shot hash should not fail on small inputs");
    let expected = hex::decode(expected_hex).expect("test vector hex should be valid");
    assert_eq!(
        actual,
        expected,
        "{algo:?}({input:?}) mismatch: expected {expected_hex}, got {}",
        hex::encode(&actual),
    );
    assert_eq!(
        actual.len(),
        algo.digest_len(),
        "digest length must match algo.digest_len()"
    );
}

/// Helper: assert the streaming API matches the expected hex digest by
/// feeding the input as a single chunk.
#[track_caller]
fn assert_streaming(algo: HashAlgorithm, input: &[u8], expected_hex: &str) {
    let mut hasher =
        Hasher::new(algo).expect("Hasher::new should not fail under normal conditions");
    hasher
        .update(input)
        .expect("update should not fail on small inputs");
    let actual = hasher.finalize();
    let expected = hex::decode(expected_hex).expect("test vector hex should be valid");
    assert_eq!(
        actual,
        expected,
        "{algo:?} streaming({input:?}) mismatch: expected {expected_hex}, got {}",
        hex::encode(&actual),
    );
}

/// Helper: assert the streaming API matches when input is split across
/// two updates. Validates the streaming state-machine invariants.
#[track_caller]
fn assert_streaming_split(algo: HashAlgorithm, input: &[u8], expected_hex: &str) {
    if input.len() < 2 {
        return; // splitting is meaningless for inputs < 2 bytes
    }
    let split = input.len() / 2;
    let mut hasher = Hasher::new(algo).expect("Hasher::new should not fail");
    hasher
        .update(&input[..split])
        .expect("update first half should not fail");
    hasher
        .update(&input[split..])
        .expect("update second half should not fail");
    let actual = hasher.finalize();
    let expected = hex::decode(expected_hex).expect("test vector hex should be valid");
    assert_eq!(
        actual,
        expected,
        "{algo:?} streaming-split({input:?}) mismatch: expected {expected_hex}, got {}",
        hex::encode(&actual),
    );
}

/// Run all three API surfaces against the same vector.
#[track_caller]
fn assert_all_apis(algo: HashAlgorithm, input: &[u8], expected_hex: &str) {
    assert_oneshot(algo, input, expected_hex);
    assert_streaming(algo, input, expected_hex);
    assert_streaming_split(algo, input, expected_hex);
}

// ─── FIPS 180-4 SHA-2 ─────────────────────────────────────────────────

#[test]
fn sha256_empty_fips_180_4() {
    assert_all_apis(
        HashAlgorithm::Sha256,
        b"",
        "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    );
}

#[test]
fn sha256_abc_fips_180_4() {
    assert_all_apis(
        HashAlgorithm::Sha256,
        b"abc",
        "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
    );
}

#[test]
fn sha384_empty_fips_180_4() {
    assert_all_apis(
        HashAlgorithm::Sha384,
        b"",
        "38b060a751ac96384cd9327eb1b1e36a21fdb71114be07434c0cc7bf63f6e1da274edebfe76f65fbd51ad2f14898b95b",
    );
}

#[test]
fn sha384_abc_fips_180_4() {
    assert_all_apis(
        HashAlgorithm::Sha384,
        b"abc",
        "cb00753f45a35e8bb5a03d699ac65007272c32ab0eded1631a8b605a43ff5bed8086072ba1e7cc2358baeca134c825a7",
    );
}

#[test]
fn sha512_empty_fips_180_4() {
    assert_all_apis(
        HashAlgorithm::Sha512,
        b"",
        "cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e",
    );
}

#[test]
fn sha512_abc_fips_180_4() {
    assert_all_apis(
        HashAlgorithm::Sha512,
        b"abc",
        "ddaf35a193617abacc417349ae20413112e6fa4e89a97ea20a9eeee64b55d39a2192992a274fc1a836ba3c23a3feebbd454d4423643ce80e2a9ac94fa54ca49f",
    );
}

// ─── FIPS 202 SHA-3 ──────────────────────────────────────────────────

#[test]
fn sha3_256_empty_fips_202() {
    assert_all_apis(
        HashAlgorithm::Sha3_256,
        b"",
        "a7ffc6f8bf1ed76651c14756a061d662f580ff4de43b49fa82d80a4b80f8434a",
    );
}

#[test]
fn sha3_256_abc_fips_202() {
    assert_all_apis(
        HashAlgorithm::Sha3_256,
        b"abc",
        "3a985da74fe225b2045c172d6bd390bd855f086e3e9d525b46bfe24511431532",
    );
}

#[test]
fn sha3_384_empty_fips_202() {
    assert_all_apis(
        HashAlgorithm::Sha3_384,
        b"",
        "0c63a75b845e4f7d01107d852e4c2485c51a50aaaa94fc61995e71bbee983a2ac3713831264adb47fb6bd1e058d5f004",
    );
}

#[test]
fn sha3_384_abc_fips_202() {
    assert_all_apis(
        HashAlgorithm::Sha3_384,
        b"abc",
        "ec01498288516fc926459f58e2c6ad8df9b473cb0fc08c2596da7cf0e49be4b298d88cea927ac7f539f1edf228376d25",
    );
}

#[test]
fn sha3_512_empty_fips_202() {
    assert_all_apis(
        HashAlgorithm::Sha3_512,
        b"",
        "a69f73cca23a9ac5c8b567dc185a756e97c982164fe25859e0d1dcc1475c80a615b2123af1f5f94c11e3e9402c3ac558f500199d95b6d3e301758586281dcd26",
    );
}

#[test]
fn sha3_512_abc_fips_202() {
    assert_all_apis(
        HashAlgorithm::Sha3_512,
        b"abc",
        "b751850b1a57168a5693cd924b6b096e08f621827444f70d884f5d0240d2712e10e116e9192af3c91a7ec57647e3934057340b4cf408d5a56592f8274eec53f0",
    );
}

// ─── RFC 7693 BLAKE2 ─────────────────────────────────────────────────

#[test]
fn blake2b_empty_rfc_7693() {
    assert_all_apis(
        HashAlgorithm::Blake2b512,
        b"",
        "786a02f742015903c6c6fd852552d272912f4740e15847618a86e217f71f5419d25e1031afee585313896444934eb04b903a685b1448b755d56f701afe9be2ce",
    );
}

#[test]
fn blake2b_abc_rfc_7693() {
    assert_all_apis(
        HashAlgorithm::Blake2b512,
        b"abc",
        "ba80a53f981c4d0d6a2797b69f12f6e94c212f14685ac4b74b12bb6fdbffa2d17d87c5392aab792dc252d5de4533cc9518d38aa8dbf1925ab92386edd4009923",
    );
}

#[test]
fn blake2s_empty_rfc_7693() {
    assert_all_apis(
        HashAlgorithm::Blake2s256,
        b"",
        "69217a3079908094e11121d042354a7c1f55b6482ca1a51e1b250dfd1ed0eef9",
    );
}

#[test]
fn blake2s_abc_rfc_7693() {
    assert_all_apis(
        HashAlgorithm::Blake2s256,
        b"abc",
        "508c5e8c327c14e2e1a72ba34eeb452f37458b209ed63a294d999b4c86675982",
    );
}

// ─── Convenience-alias parity ────────────────────────────────────────
//
// Each algorithm-specific function (`sha256`, `sha512`, etc.) returns
// the same digest as the agile `hash(HashAlgorithm::*, ...)` API for
// any input. The alias is a thin wrapper that converts `Vec<u8>` to a
// fixed-size array; the tests below verify the wrapper does not corrupt
// the output.

#[test]
fn convenience_aliases_match_agile_api() {
    use pulsar_kernel::crypto::hash::{
        blake2b512, blake2s256, sha3_256, sha3_384, sha3_512, sha256, sha384, sha512,
    };

    let input = b"the quick brown fox jumps over the lazy dog";

    assert_eq!(
        sha256(input).unwrap().as_slice(),
        hash(HashAlgorithm::Sha256, input).unwrap().as_slice(),
    );
    assert_eq!(
        sha384(input).unwrap().as_slice(),
        hash(HashAlgorithm::Sha384, input).unwrap().as_slice(),
    );
    assert_eq!(
        sha512(input).unwrap().as_slice(),
        hash(HashAlgorithm::Sha512, input).unwrap().as_slice(),
    );
    assert_eq!(
        sha3_256(input).unwrap().as_slice(),
        hash(HashAlgorithm::Sha3_256, input).unwrap().as_slice(),
    );
    assert_eq!(
        sha3_384(input).unwrap().as_slice(),
        hash(HashAlgorithm::Sha3_384, input).unwrap().as_slice(),
    );
    assert_eq!(
        sha3_512(input).unwrap().as_slice(),
        hash(HashAlgorithm::Sha3_512, input).unwrap().as_slice(),
    );
    assert_eq!(
        blake2b512(input).unwrap().as_slice(),
        hash(HashAlgorithm::Blake2b512, input).unwrap().as_slice(),
    );
    assert_eq!(
        blake2s256(input).unwrap().as_slice(),
        hash(HashAlgorithm::Blake2s256, input).unwrap().as_slice(),
    );
}
