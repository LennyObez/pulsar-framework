# Changelog

All notable changes to Pulsar Framework are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Sprint 1.1 — kernel crypto (Phase 1.1.C.3: hybrid X25519+ML-KEM-768 KEM, in progress)

Third sub-phase of Phase 1.1.C. Lands `pulsar-kernel::crypto::hybrid_kem` — the **hybrid X25519+ML-KEM-768 key-encapsulation mechanism** per Decision 2.58 (every Pulsar protocol that uses key encapsulation runs both KEMs in parallel and combines the shared secrets via HKDF-SHA-256). Defence-in-depth posture: a future quantum adversary cannot break the ML-KEM-768 share, and a future structural attack against ML-KEM-768 leaves the X25519 share at 128-bit-equivalent classical security. The two schemes share neither hardness assumption nor implementation surface. Phase 1.1.C.4 will land the symmetric Ed25519+ML-DSA-65 hybrid signature.

* **`pulsar-kernel::crypto::hybrid_kem`** — typed safe wrappers orchestrating the X25519 + ML-KEM-768 primitives shipped by Phase 1.1.B.3 and Phase 1.1.C.1. `HybridKemKeyPair::try_generate()` independently generates an X25519 keypair (32-byte CSPRNG scalar) and an ML-KEM-768 keypair (64-byte CSPRNG seed) — the two share no entropy. `HybridKemPublicKey::try_encapsulate()` generates an ephemeral X25519 keypair, computes `ss_x25519 = eph_sk.dh(peer_x25519)` + runs `ML-KEM-768::encapsulate(peer_mlkem)`, then combines via HKDF-SHA-256 (salt = `pulsar-hybrid-kem-x25519-mlkem768-v1`, IKM = `ss_x25519 || ss_mlkem`). `HybridKemPrivateKey::try_decapsulate(&ct)` is symmetric. `validate()` on the public key delegates to `MlKem768PublicKey::validate` (FIPS 203 § 7.2); the X25519 share is validated lazily at encapsulation time via HACL\*'s small-order rejection.
* **Wire format** — IETF X25519MLKEM768 codepoint convention: `pk_x25519 || pk_mlkem` (32 + 1 184 = 1 216 bytes), ciphertext `eph_pk_x25519 || ct_mlkem` (32 + 1 088 = 1 120 bytes). `to_bytes` / `from_bytes` round-trip verified by tests. Wire-format private-key length 2 432 bytes (32 + 2 400) — though the hybrid private key is held in memory as `(X25519PrivateKey, MlKem768PrivateKey)` rather than a serialized buffer.
* **HKDF combiner (Decision 2.58)** — `HKDF-SHA-256(salt = pulsar-hybrid-kem-x25519-mlkem768-v1, IKM = ss_x25519 || ss_mlkem, info = "", length = 32)`. The salt label provides domain separation against any other Pulsar HKDF use of the same IKM material. The combiner is structurally HKDF-Extract followed by HKDF-Expand to 32 bytes (calls into the existing `crypto::hkdf::hkdf` convenience helper).
* **Constituent-primitive zeroization** — the hybrid private key holds an `X25519PrivateKey` (SecretBox-wrapped 32-byte scalar) alongside an `MlKem768PrivateKey` (SecretBox-wrapped 2 400-byte private key). Both inherit the canonical Pulsar zeroize-on-drop posture from Phase 1.1.B.3 / Phase 1.1.C.1 — no new private-key storage introduced.
* **`X25519PrivateKey::try_generate()`** — added in this phase to support hybrid-KEM ephemeral keypair generation. Draws 32 bytes via `crate::crypto::rng::try_random_bytes` and constructs via `from_bytes`. Same convenience API also added to `Ed25519PrivateKey::try_generate()` for the upcoming Phase 1.1.C.4 hybrid signature.
* **Module re-exports** in `pulsar-kernel::crypto::mod` and `pulsar-kernel::prelude`: `HybridKemKeyPair`, `HybridKemPublicKey`, `HybridKemPrivateKey`, `HybridKemCiphertext`. The `sizes` sub-module is reachable via `pulsar_kernel::crypto::hybrid_kem::sizes`.

**Tests** (14 new tests across two integration test files):

* **`tests/hybrid_kem_kat.rs`** (10 tests):
  - sizes match IETF X25519MLKEM768 codepoint (1216 / 2432 / 1120 / 32) — pinned via constants
  - encap/decap round-trip — encapsulator's combined shared secret equals decapsulator's combined shared secret
  - public-key wire format round-trip (`to_bytes` ↔ `from_bytes`) — preserves both X25519 and ML-KEM shares byte-identically
  - ciphertext wire format round-trip — recovered ciphertext decapsulates to identical shared secret
  - distinct hybrid keypairs yield distinct public keys (probabilistic)
  - `validate()` accepts a fresh hybrid public key (delegates to ML-KEM § 7.2 modulus check)
  - tampered X25519 ephemeral share (replaced with all-zeros small-order point) → `InvalidPublicKey` from HACL\*'s F\* postcondition rejection
  - tampered ML-KEM share (single-bit flip) → divergent shared secret per FIPS 203 § 7.3 implicit rejection (decap doesn't error, just produces uncorrelated output)
  - tampered X25519 share (non-small-order single-bit flip) → divergent shared secret (validates that the HKDF combiner actually mixes the X25519 share — a wrapper bug that ignored `ss_x25519` would produce identical output despite tampering)
  - `Debug` redaction on hybrid private key (`finish_non_exhaustive`)
* **`tests/hybrid_kem_property.rs`** (4 properties using `proptest`, 32 cases each — hybrid ops cost ~250 µs):
  - encap/decap round-trip across CSPRNG-driven keypairs
  - distinct keypairs yield distinct public keys
  - distinct encap calls under same public key yield distinct ciphertexts (both X25519 ephemeral and ML-KEM seed are fresh per call)
  - wire-format `to_bytes` / `from_bytes` is a perfect round-trip preserving KEM correctness

Quality gates verified locally:
  - cargo fmt --all -- --check ✓
  - cargo clippy -p pulsar-kernel --all-targets -- -D warnings ✓
  - cargo nextest run -p pulsar-kernel (178/178 PASS, +14 from this phase) ✓
  - cargo test -p pulsar-kernel --doc ✓

### Sprint 1.1 — kernel crypto (Phase 1.1.C.2: ML-DSA-65 safe wrapper + KATs — merged 2026-04-28 as `60521c16`)

Second sub-phase of Phase 1.1.C (post-quantum safe wrappers per Decision 2.60). Lands `pulsar-kernel::crypto::ml_dsa` — the FIPS 204 ML-DSA-65 digital-signature primitive sourced from `libcrux-ml-dsa 0.0.6` (Cryspen Rust-native, hax + F\* verified). NIST security category 3 ≈ AES-192. Pairs with Phase 1.1.C.1's ML-KEM-768; together they cover NIST's two PQC standards (FIPS 203 KEM + FIPS 204 signature). Phase 1.1.C.3 will combine ML-DSA-65 with Ed25519 to form the Ed25519+ML-DSA-65 hybrid signature per Decision 2.58.

* **`pulsar-kernel::crypto::ml_dsa`** — typed safe wrappers over `libcrux_ml_dsa::ml_dsa_65`. `MlDsa65KeyPair::try_generate()` draws 32 bytes via the Pulsar CSPRNG; `try_from_seed(&seed)` takes a caller-supplied 32-byte seed for deterministic test-vector replay. `MlDsa65SigningKey::try_sign(message, context)` draws 32 bytes of CSPRNG randomness for FIPS 204 § 5.4's randomised-by-default signing; `try_sign_with_seed(message, context, &seed)` lets callers inject the randomness for testing. `MlDsa65VerificationKey::verify(message, context, &signature)` returns `Ok(())` on valid / `Err(SignatureVerifyFailed)` on invalid (collapses every libcrux `VerificationError` variant into a single error with no information leaked about which sub-step failed — matches the [`Ed25519PublicKey::verify`] posture).
* **Context parameter (FIPS 204 § 5.2 domain separation)** — `sign` / `verify` accept a `&[u8]` context of length ≤ 255 used to separate signing-key reuse across protocols. Empty context (`b""`) is the no-domain-separation default. Mismatched contexts at sign vs verify time cause verification to fail. Excess-length contexts (> 255 bytes) are rejected as `Error::InputTooLong { actual, max: 255 }` at both sign and verify entry points.
* **Sizes (FIPS 204 ML-DSA-65)** — verification (public) key 1 952 bytes, signing (private) key 4 032 bytes, signature 3 309 bytes, keypair-generation seed 32 bytes, signing randomness seed 32 bytes, max context 255 bytes. Re-exposed as `pulsar_kernel::crypto::ml_dsa::sizes::*` constants.
* **Key-material handling** — signing keys store the 4 032-byte private key in `secrecy::SecretBox<[u8]>` (zeroize-on-drop) per the canonical Pulsar convention, matching `Ed25519PrivateKey` / `X25519PrivateKey` / `MlKem768PrivateKey`. The wrapper materialises libcrux's `MLDSA65SigningKey` value transiently for each `try_sign*` call via a `zeroize::Zeroizing<[u8; 4032]>` stack buffer; the libcrux transient lives ~µs and falls out of scope without explicit zeroization (libcrux 0.0.6 does not implement `Zeroize`). Stack-side intermediate copies (keygen seeds, signing randomness seeds, signing-key conversion buffers) are wrapped in `Zeroizing`. Verification keys + signatures are unwrapped (non-secret per the digital-signature threat model). `MlDsa65SigningKey::from_bytes` consumes the input `SecretBox<[u8]>` (matches the canonical consume-pattern documented in `crypto::mod`).
* **Error enum extension** — one new variant: `SigningFailed` (libcrux's rejection-sampling loop in FIPS 204 § 5.4 exceeded the maximum number of attempts — vanishingly rare; treat as fatal-but-transient).
* **Module re-exports** in `pulsar-kernel::crypto::mod` and `pulsar-kernel::prelude`: `MlDsa65KeyPair`, `MlDsa65SigningKey`, `MlDsa65VerificationKey`, `MlDsa65Signature`. The `sizes` sub-module is reachable via `pulsar_kernel::crypto::ml_dsa::sizes`.

**Tests** (17 new tests across two integration test files):

* **`tests/ml_dsa_kat.rs`** (12 tests):
  - sizes match FIPS 204 ML-DSA-65 (4032/1952/3309/32/32/255) — pinned via constants
  - sign/verify round-trip with fixed seed (reproducible across CI runs)
  - seed-driven keypair generation is deterministic (same seed → same verification key)
  - signing with a fixed randomness seed is deterministic (same `(sk, message, context, signing_seed)` → same signature)
  - verification rejects tampered messages → `SignatureVerifyFailed`
  - verification rejects tampered signatures (single-bit flip) → `SignatureVerifyFailed`
  - FIPS 204 § 5.2 domain separation — context mismatch at sign vs verify → `SignatureVerifyFailed`
  - verification rejects wrong (unrelated) verification keys
  - signing rejects oversize context (256 bytes) → `InputTooLong { actual: 256, max: 255 }`
  - verifying rejects oversize context → `InputTooLong { actual: 256, max: 255 }`
  - length-validation paths for keygen seed (31 vs 32), signing key (4031 vs 4032), signing seed (31 vs 32) → `InvalidKeyLength`
  - `Debug` redacts signing-key bytes (`finish_non_exhaustive` `..` marker), verification-key Debug shows hex prefix
* **`tests/ml_dsa_property.rs`** (5 properties using `proptest`, 32 cases each — ML-DSA ops cost 100s of µs):
  - sign/verify round-trip across random `(keygen_seed, signing_seed, message)` triples
  - distinct keygen seeds yield distinct verification keys (probabilistic — collision bounded by 2⁻¹⁵⁰⁰⁰ish on 1 952-byte keys)
  - distinct signing seeds yield distinct signatures under same `(sk, message, context)` (FIPS 204 § 5.4 randomised signing)
  - verification rejects tampered messages (single-bit flip) → `SignatureVerifyFailed`
  - verification rejects signatures verified under wrong verification keys

Quality gates verified locally:
  - cargo fmt --all -- --check ✓
  - cargo clippy -p pulsar-kernel --all-targets -- -D warnings ✓
  - cargo nextest run -p pulsar-kernel (164/164 PASS, +17 from this phase) ✓
  - cargo test -p pulsar-kernel --doc ✓

### Sprint 1.1 — kernel crypto (Phase 1.1.C.1: ML-KEM-768 safe wrapper + KATs — merged 2026-04-28 as `a8854e7b`)

First sub-phase of Phase 1.1.C (post-quantum safe wrappers per Decision 2.60). Lands `pulsar-kernel::crypto::ml_kem` — the FIPS 203 ML-KEM-768 key-encapsulation primitive sourced from `libcrux-ml-kem 0.0.8` (Cryspen Rust-native, hax + F\* verified, NIST security category 3 ≈ AES-192). Phase 1.1.C.2 lands ML-DSA-65 (FIPS 204) and Phase 1.1.C.3 the hybrid X25519+ML-KEM-768 / Ed25519+ML-DSA-65 constructions per Decision 2.58.

* **`pulsar-kernel::crypto::ml_kem`** — typed safe wrappers over `libcrux_ml_kem::mlkem768`. `MlKem768KeyPair::try_generate()` draws 64 bytes via the Pulsar CSPRNG (Phase 1.1.B.5) and returns `(public_key, private_key)`; `try_from_seed(&seed)` takes a caller-supplied 64-byte seed for deterministic test-vector replay. `MlKem768PublicKey::try_encapsulate()` draws 32 bytes of CSPRNG randomness and returns `(ciphertext, shared_secret)`; `try_encapsulate_with_seed(&seed)` lets callers inject randomness. `MlKem768PrivateKey::decapsulate(&ct)` is deterministic and infallible — FIPS 203 § 7.3 implicit rejection means tampered ciphertexts yield deterministic-but-uncorrelated shared secrets that the protocol layer treats as authentication failure. `validate()` on the public key + `validate_against(&ct)` on the private key surface the FIPS 203 § 7.2/7.3 structural checks for untrusted-input paths.
* **Sizes (FIPS 203 ML-KEM-768)** — public key 1 184 bytes, private key 2 400 bytes, ciphertext 1 088 bytes, shared secret 32 bytes, keypair-generation seed 64 bytes, encapsulation seed 32 bytes. Re-exposed as `pulsar_kernel::crypto::ml_kem::sizes::*` constants for caller-side allocation sizing.
* **Key-material handling** — private keys store the 2 400-byte buffer in `secrecy::SecretBox<[u8]>` (zeroize-on-drop) per Pulsar's canonical convention, matching `Ed25519PrivateKey` / `X25519PrivateKey`. The wrapper materialises libcrux's `MlKem768PrivateKey` value transiently for each `decapsulate` / `validate_against` call via a `zeroize::Zeroizing<[u8; 2400]>` stack buffer that zeroizes itself on drop; the resulting libcrux instance lives ~µs on the stack and falls out of scope without explicit zeroization (libcrux 0.0.8 does not implement `Zeroize`). Stack-side intermediate copies (encap seeds, keygen seeds) are wrapped in `Zeroizing` so the only un-zeroized secret-bearing memory is the libcrux transient — acceptable under the regulated-domain audit posture because the trust window is microseconds and subsequent stack frames overwrite the bytes. Shared secrets returned from encapsulate/decapsulate are wrapped in `SecretBox<[u8]>` so downstream HKDF derivation operates on `expose_secret()` without intermediate cleartext copies. Public keys + ciphertexts are unwrapped (non-secret per the KEM threat model). `MlKem768PrivateKey::from_bytes` consumes the input `SecretBox<[u8]>` (matches the canonical consume-pattern documented in `crypto::mod`).
* **Error enum extension** — one new variant: `InvalidPrivateKey` (private key fails structural self-consistency check, e.g., FIPS 203 § 7.3 hash check). Distinct from `InvalidPublicKey`, which covers peer-supplied encapsulation-key validation failures.
* **Module re-exports** in `pulsar-kernel::crypto::mod` and `pulsar-kernel::prelude`: `MlKem768KeyPair`, `MlKem768PublicKey`, `MlKem768PrivateKey`, `MlKem768Ciphertext`. The `sizes` sub-module is reachable via `pulsar_kernel::crypto::ml_kem::sizes`.

**Tests** (16 new tests across two integration test files):

* **`tests/ml_kem_kat.rs`** (11 tests):
  - sizes match FIPS 203 ML-KEM-768 (1184/2400/1088/32/64/32) — pinned via constants
  - encap/decap round-trip with fixed seed (reproducible across CI runs)
  - seed-driven keypair generation is deterministic (same seed → same keypair)
  - encapsulation with the same `(pk, encap_seed)` is deterministic (same ciphertext + shared secret on repeat calls)
  - `validate()` accepts authentic public keys
  - `validate_against()` accepts authentic `(private_key, ciphertext)` pairs
  - `validate_against()` rejects a corrupted private key (all-zeros buffer) → `InvalidPrivateKey`
  - FIPS 203 § 7.3 implicit rejection — tampered ciphertext under genuine private key yields deterministic-but-uncorrelated shared secret (≠ encapsulator's secret + identical across repeat decap calls)
  - `try_from_seed` rejects wrong-length seeds (63/65 vs 64) → `InvalidKeyLength`
  - `from_bytes` rejects wrong-length private keys (2399 vs 2400) → `InvalidKeyLength`
  - `try_encapsulate_with_seed` rejects wrong-length encap seeds (31 vs 32) → `InvalidKeyLength`
  - `Debug` redacts private-key bytes (`finish_non_exhaustive` `..` marker), public-key Debug shows hex prefix
* **`tests/ml_kem_property.rs`** (5 properties using `proptest`, 32 cases each — ML-KEM operations cost 10s of µs):
  - encap/decap round-trip across random `(keygen_seed, encap_seed)` pairs
  - distinct keygen seeds yield distinct public keys (probabilistic — collision bounded by 2⁻⁹⁰⁰⁰ish on 1184-byte keys)
  - distinct encap seeds yield distinct ciphertexts under same public key (probabilistic — bounded by 2⁻⁸⁷⁰⁴)
  - implicit rejection is deterministic — single-bit ciphertext flip + repeat decap yields byte-identical rejection secrets
  - implicit rejection secret differs from authentic encapsulator secret (probabilistic — bounded by 2⁻²⁵⁶)

Quality gates verified locally:
  - cargo fmt --all -- --check ✓
  - cargo clippy -p pulsar-kernel --all-targets -- -D warnings ✓
  - cargo nextest run -p pulsar-kernel (147/147 PASS, +17 from this phase) ✓
  - cargo test -p pulsar-kernel --doc ✓

### Sprint 1.1 — kernel crypto (Phase 1.1.B.5: CSPRNG abstraction + Argon2id random-salt convenience — merged 2026-04-28 as `a9778a73`)

Fifth sub-phase of Phase 1.1.B. Lands `pulsar-kernel::crypto::rng` — the kernel-level CSPRNG abstraction over the OS entropy source (`getrandom(2)` on Linux, `BCryptGenRandom` on Windows, `SecRandomCopyBytes` on macOS) via the `rand` / `rand_core` ecosystem's `OsRng`. The OS interface is the only entropy source Pulsar uses for cryptographic operations — no userspace PRNG is permitted on the cryptographic-key path per the regulated-domain audit posture. Phase 1.1.C lands the libcrux post-quantum primitives (ML-KEM-768 + ML-DSA-65) which depend on this RNG abstraction for keypair generation and ML-KEM encapsulation.

* **`pulsar-kernel::crypto::rng`** — CSPRNG entry points. `try_random_bytes(n: usize) -> Result<Vec<u8>>` allocates a fresh `Vec<u8>` filled with `n` cryptographically-secure random bytes; `try_random_into(&mut [u8]) -> Result<()>` writes random bytes into a caller-provided slice (slice-API parity with the Vec-allocating form, suitable for stack buffers). Both surfaces are fallible — the OS-level call can fail under documented degenerate cases (very early boot, exhausted entropy pool on minimal kernels, sandboxes blocking the syscall) and the failure is surfaced as `Error::RngFailure`. Pulsar deliberately does NOT expose `rand::RngCore::fill_bytes`-style infallible variants per the project's "no panics in library code" policy.
* **Custom RNG injection** — `rand::CryptoRng` and `rand::RngCore` are re-exported as `crypto::rng::CryptoRng` and `crypto::rng::RngCoreTrait` for callers (test infrastructure, deterministic-failure debugging) that pass a seeded RNG; production paths should use the concrete `OsRng`-backed helpers above.
* **`crypto::argon2::hash_password_with_random_salt`** — Argon2id PHC-string password hashing with a fresh CSPRNG-generated 16-byte salt. Equivalent to [`hash_password`] but generates the salt internally via [`crypto::rng::try_random_bytes`], removing the per-call salt-generation responsibility from the caller (the most common implementation defect in password handling). Each call produces a unique PHC string even for the same `(password, params)` input. OWASP "≥ 16 bytes from a CSPRNG" recommendation enforced at the call site.
* **Error enum extension** — one new variant: `RngFailure` (CSPRNG entropy source failed). Documented as fatal-but-transient — production code should abort the in-flight operation rather than retry with reduced entropy.

**Tests** (9 new tests across two integration test files):

* **`tests/rng_property.rs`** (4 properties using `proptest` + 2 unit tests):
  - `random_bytes` length matches request across `[0, 1024)`
  - distinct calls yield distinct 32-byte outputs (probabilistic)
  - `random_into` fills the entire slice (non-zero output for n ≥ 1)
  - slice-API parity (`try_random_into` and `try_random_bytes` produce same-length output)
  - zero-length `try_random_bytes(0)` returns empty `Vec` without panic
  - zero-length `try_random_into(&mut [])` is a no-op without panic
* **`tests/argon2_random_salt.rs`** (3 tests):
  - round-trip — produce PHC string with random salt, verify original password
  - distinct PHC strings — two calls with same `(password, params)` produce different outputs (different salts)
  - wrong-password rejection still fires when salt was randomly generated → `PasswordVerifyFailed`

Quality gates verified locally:
  - cargo fmt --all -- --check ✓
  - cargo clippy -p pulsar-kernel --all-targets -- -D warnings ✓
  - cargo nextest run -p pulsar-kernel (131/131 PASS, +9 from this phase) ✓
  - cargo test -p pulsar-kernel --doc ✓

### Sprint 1.1 — kernel crypto (Phase 1.1.B.4: HMAC + HKDF + Argon2id safe wrappers — merged 2026-04-28 as `2da454a1`)

Fourth sub-phase of Phase 1.1.B. Lands `pulsar-kernel::crypto::hmac` (HMAC-SHA-2 + HMAC-BLAKE2 per RFC 4231 / FIPS 198-1 / RFC 7693), `pulsar-kernel::crypto::hkdf` (HKDF per RFC 5869 over the same HMAC algorithm enum), and `pulsar-kernel::crypto::argon2` (Argon2id per RFC 9106 via RustCrypto's audited `argon2` 0.5 crate per Decision 2.60). Phase 1.1.B sub-phases now cover hash + AEAD + signature + KEM + HMAC + HKDF + Argon2id; Phase 1.1.C ships the post-quantum primitives (ML-KEM-768 + ML-DSA-65) via libcrux.

* **`pulsar-kernel::crypto::hmac`** — keyed message authentication via `EverCrypt_HMAC_compute`. `HmacAlgorithm` `#[non_exhaustive]` enum covers HMAC-SHA-2-256/384/512 and HMAC-BLAKE2b/2s; HMAC-SHA-1 + HMAC-MD5 deliberately absent per Pulsar's compliance posture (NIST SP 800-131A deprecates SHA-1 HMAC). `HmacKey::new(algo, key: SecretBox<[u8]>)` consumes the secret material per the canonical pattern (wrapper retains for repeated `compute` calls). `compute(data) -> Vec<u8>` and `compute_into(data, &mut [u8])` produce a `tag_len()`-byte tag. `verify(data, expected_tag)` performs constant-time comparison via `subtle::ConstantTimeEq`, returning `Ok(())` on match or `Err(MacVerifyFailed)` on mismatch. Zero information leaked about which byte position differed.
* **`pulsar-kernel::crypto::hkdf`** — HMAC-based key derivation via `EverCrypt_HKDF_extract` / `EverCrypt_HKDF_expand`. Reuses `HmacAlgorithm` (RFC 5869 parameterises HKDF by an HMAC choice). `extract(algo, salt, ikm: &SecretBox<[u8]>) -> SecretBox<[u8]>` derives the PRK; `expand(algo, prk: &SecretBox<[u8]>, info, length) -> SecretBox<[u8]>` derives the OKM. Combined `hkdf(...)` chains the two for callers that don't need to expose the intermediate PRK. RFC 5869 § 2.3 max-output-length bound (`255 * tag_len()`) and zero-length output rejection enforced as `Error::InvalidOutputLength`. Salt + info are public per RFC 5869 § 3.1 (accepted as `&[u8]`); IKM, PRK, and OKM are wrapped in `SecretBox<[u8]>` for downstream zeroize-on-drop.
* **`pulsar-kernel::crypto::argon2`** — Argon2id password hashing via RustCrypto's `argon2` 0.5 (audited, not formally verified per Decision 2.60 — HACL\* does not ship Argon2). Variant-locked to **Argon2id** only (RFC 9106 § 4 recommends Argon2id as the side-channel-resistant + time-memory-trade-off-resistant default). `Argon2idParams` struct exposes `m_cost` (KiB), `t_cost` (iterations), `p_cost` (lanes), `output_len`. `Argon2idParams::default()` returns the OWASP 2024 "minimum recommended" profile (m=19456 KiB, t=2, p=1, output=32). `validate()` enforces RFC 9106 § 3.1 admissible bounds (`p_cost ≤ 2²⁴-1`, `m_cost ≥ 8 * p_cost`, `output_len ≥ 4`). Three call surfaces: `derive_key(password, salt, params, &mut output)` for raw KDF use, `hash_password(password, salt, params) -> String` for PHC-string password storage, `verify_password(password, phc_string)` for constant-time verification with cross-algorithm rejection (Argon2i / Argon2d PHC strings rejected as `InvalidPhcString`). Salt minimum enforced at 8 bytes per RFC 9106 § 3.1; OWASP recommends ≥ 16 for production. RNG-backed salt-generating convenience methods deferred to a future kernel-wide RNG sub-phase.
* **PHC-embedded parameter bounds** — `verify_password` enforces upper-bound limits on the `(m, t, p, output_len)` cost parameters embedded in the PHC string before any KDF work runs, defending against attacker-controlled PHC strings that would otherwise force arbitrarily expensive verification. `Argon2idVerifyLimits::DEFAULT` ceilings (m ≤ 1 GiB, t ≤ 10, p ≤ 16, output ≤ 64 bytes) accommodate any reasonable production profile while rejecting the obvious extremes; `verify_password_with_limits` lets tight-SLO callers tighten further. Over-limit PHC strings are rejected as `InvalidArgon2Params` with a structured `reason` field.
* **Error enum extensions** — four new variants in `pulsar_kernel::error::Error`: `MacVerifyFailed` (HMAC tag mismatch), `PasswordVerifyFailed` (Argon2id password mismatch), `InvalidArgon2Params { reason: &'static str }` (parameter-bound violation including over-limit PHC params), `InvalidPhcString` (malformed or non-Argon2id PHC string).
* **Module re-exports** in `pulsar-kernel::crypto::mod` and `pulsar-kernel::prelude`: `HmacAlgorithm`, `HmacKey`, `Argon2idParams`, `Argon2idVerifyLimits`. HKDF is accessed via `pulsar_kernel::crypto::hkdf::{extract, expand, hkdf}` (no struct surface).
* **Code organisation** — `ensure_initialized` lifted from per-sub-module duplicates (six identical copies across hash / aead / signature / kem / hmac / hkdf) into `crypto::mod` as `pub(crate) fn` (single shared `Once` static, no behaviour change). `HmacAlgorithm::to_ffi` made `pub(crate)` so `crypto::hkdf` can reuse the canonical algorithm-id mapping instead of duplicating it. Same DRY posture as the `hex_encode_short` lift in Phase 1.1.B.3.

**Tests** (40 new tests across six integration test files):

* **`tests/hmac_kat.rs`** (12 tests): RFC 4231 § 4.2/4.3/4.4 Test Cases 1, 2, 3 for HMAC-SHA-256/384/512; verify-rejects-tampered-tag + tampered-message + wrong-length-tag (`MacVerifyFailed` / `InvalidOutputLength`); `compute_into` short-buffer rejection; `Debug` impl redacts key bytes; algorithm-constant verification (`tag_len`).
* **`tests/hmac_property.rs`** (7 properties using `proptest`): compute/verify round-trip, determinism, slice-API parity, single-bit tag-tampering rejection, single-bit message-tampering rejection, distinct-keys-yield-distinct-tags, distinct-messages-yield-distinct-tags. Inputs span all five algorithms × 0-1024-byte messages.
* **`tests/hkdf_kat.rs`** (6 tests): RFC 5869 Appendix A.1 (full PRK + OKM verification), A.2 (PRK only — 80-byte inputs), A.3 (zero-length salt + info); `expand` rejects zero-length and over-`255*tag_len()` requests; `expand` accepts maximum-length output for every supported algorithm.
* **`tests/hkdf_property.rs`** (6 properties using `proptest`): combined-matches-decomposed, determinism, distinct-IKM-yields-distinct-OKM, distinct-salt, distinct-info, OKM length matches request. Inputs span all five algorithms.
* **`tests/argon2_kat.rs`** (16 tests): pinned KAT for `derive_key` (catches upstream regression in the `argon2` 0.5.3 substrate), hash + verify round-trip, wrong-password rejection (`PasswordVerifyFailed`), malformed PHC string rejection (`InvalidPhcString`), Argon2i PHC string rejection, parameter validation (p_cost=0, t_cost=0, m_cost < 8*p_cost, output_len < 4), wrong output length, short-salt rejection (8-byte minimum), default-params validation. Plus 6 tests for `Argon2idVerifyLimits`: default-ceilings constants verification + over-limit PHC rejection on `m_cost`, `t_cost`, `p_cost` + `verify_password_with_limits` accepting authentic password under exact-match limits and rejecting under tightened ceilings.
* **`tests/argon2_property.rs`** (6 properties using `proptest`): `derive_key` determinism, distinct-passwords-yield-distinct-outputs, distinct-salts-yield-distinct-outputs, hash/verify round-trip, verify rejects distinct passwords, PHC string canonical structure (six dollar-separated fields). Cases capped at 32/property to stay under the latency budget; FAST_PARAMS (m=8 KiB, t=1, p=1, output=16) chosen to satisfy both RFC 9106 § 3.1 (≥ 4) and `password_hash::Output::MIN_LENGTH` (10).

Quality gates verified locally:
  - cargo fmt --all -- --check ✓
  - cargo clippy -p pulsar-kernel --all-targets -- -D warnings ✓
  - cargo nextest run -p pulsar-kernel (122/122 PASS, +46 from this phase) ✓
  - cargo test -p pulsar-kernel --doc ✓

### Sprint 1.1 — kernel crypto (Phase 1.1.B.3: Ed25519 + X25519 safe wrappers — merged 2026-04-28 as `76a7494b`)

Third sub-phase of Phase 1.1.B (safe Rust wrappers over the HACL\* FFI). Lands `pulsar-kernel::crypto::signature` (Ed25519 per RFC 8032) and `pulsar-kernel::crypto::kem` (X25519 per RFC 7748). P-256 ECDSA + ECDH deferred to follow-up sub-phase 1.1.B.3-bis (requires deterministic-nonce machinery per RFC 6979 + secure RNG infrastructure).

* **`pulsar-kernel::crypto::signature`** — Ed25519 wrappers over `EverCrypt_Ed25519_*`. `Ed25519PrivateKey::from_bytes(SecretBox<[u8]>)` constructs from a 32-byte seed wrapped in `secrecy::SecretBox<[u8]>` (zeroize-on-drop per the canonical pattern from Phase 1.1.B.2). `public_key()` derives the 32-byte verifying key. `sign(message)` produces a 64-byte deterministic signature (RFC 8032 § 5.1.6 — Ed25519 signatures are inherently deterministic, no nonce-reuse risk). `Ed25519PublicKey::verify(message, signature)` returns `Ok(())` on valid / `Err(SignatureVerifyFailed)` on invalid (no information leaked about which step failed — HACL\*'s constant-time invariant covers success/failure paths uniformly).
* **`pulsar-kernel::crypto::kem`** — X25519 wrappers over `EverCrypt_Curve25519_*`. `X25519PrivateKey::from_bytes(SecretBox<[u8]>)` constructs from a 32-byte scalar (clamped per RFC 7748 § 5 internally by HACL\*). `public_key()` derives the 32-byte u-coordinate. `diffie_hellman(peer)` returns the 32-byte shared secret wrapped in `SecretBox<[u8]>` so downstream HKDF derivation operates on `expose_secret()` without accidental cleartext copies. Small-order public keys (e.g., u = 0) are rejected as `Error::InvalidPublicKey` per HACL\*'s F\* postcondition.
* **All `Debug` impls redact secret material**. `Ed25519PrivateKey` and `X25519PrivateKey` print only the type name; public keys + signatures show a short hex prefix (8 bytes) + length suffix for identification without dumping the full bytes.
* **Module re-exports** in `pulsar-kernel::crypto::mod` and `pulsar-kernel::prelude`: `Ed25519PrivateKey`, `Ed25519PublicKey`, `Ed25519Signature`, `X25519PrivateKey`, `X25519PublicKey`.

**Tests** (21 new tests across four integration test files):

* **`tests/signature_kat.rs`** (6 tests):
  - RFC 8032 § 7.1 TEST 1 (empty message) — verifies key derivation, signing produces canonical signature, both produced + reference signature verify
  - RFC 8032 § 7.1 TEST 2 (1-byte message 0x72) — same coverage
  - Tampered-message verify rejection → `SignatureVerifyFailed`
  - Wrong-public-key verify rejection (signature produced under key A, verified under key B) → `SignatureVerifyFailed`
  - Wrong-length seed rejection (31/33 bytes vs 32) → `InvalidKeyLength`
  - Debug impl redacts seed bytes
* **`tests/signature_property.rs`** (7 properties using `proptest`):
  - Sign/verify round-trip across random `(seed, message)` pairs
  - Signing is deterministic per RFC 8032 § 5.1.6 — same `(key, message)` always yields the same 64-byte signature
  - Distinct messages under the same key produce distinct signatures (probabilistic)
  - Distinct seeds derive distinct public keys (probabilistic)
  - Verifying a signature produced by key A under key B's public key fails with `SignatureVerifyFailed`
  - Single-bit message tampering after signing → `SignatureVerifyFailed`
  - Single-bit signature tampering → `SignatureVerifyFailed`
* **`tests/kem_kat.rs`** (3 tests):
  - RFC 7748 § 6.1 — Alice + Bob derive the same shared secret matching the reference vector; both public keys match the RFC-computed values
  - Small-order public key (u = 0) rejection → `InvalidPublicKey`
  - Wrong-length scalar rejection → `InvalidKeyLength`
* **`tests/kem_property.rs`** (5 properties using `proptest`):
  - Public-key derivation is deterministic — same seed yields byte-identical public point
  - Distinct seeds derive distinct public keys (probabilistic)
  - DH symmetry — `Alice.dh(Bob.pub)` and `Bob.dh(Alice.pub)` produce byte-identical shared secrets
  - Distinct private keys against the same peer produce distinct shared secrets (probabilistic)
  - DH is deterministic for fixed `(private, peer)` inputs

Inputs span 0-1024-byte messages across random 32-byte seeds; per-property default is 256 cases, totalling ~3000 random inputs across all properties.

**Code organisation** — `hex_encode_short` `Debug`-formatting helper hoisted from `signature.rs` + `kem.rs` into `crypto::mod` as `pub(crate) fn` (single source of truth, prevents future divergence). Module-level documentation in `crypto::mod` formalises the `SecretBox` parameter convention: `&SecretBox<[u8]>` borrowed by `AeadKey::new` (FFI immediately copies into expanded state, caller retains ownership) versus `SecretBox<[u8]>` consumed by `Ed25519PrivateKey::from_bytes` + `X25519PrivateKey::from_bytes` (wrapper retains the seed for repeated `sign` / `diffie_hellman` calls so zeroize-on-drop ownership transfers exactly once).

Quality gates verified locally:
  - cargo fmt --all -- --check ✓
  - cargo clippy -p pulsar-kernel --all-targets -- -D warnings ✓
  - cargo nextest run -p pulsar-kernel (68/68 PASS, +21 from this phase) ✓

### Sprint 1.1 — kernel crypto (Phase 1.1.B.2: AEAD safe wrappers — merged 2026-04-28 as `833f6c9a`)

Second sub-phase of Phase 1.1.B (safe Rust wrappers over the HACL\* FFI). Lands `pulsar-kernel::crypto::aead` module with the three AEAD primitives Pulsar standardises on: AES-128-GCM, AES-256-GCM, ChaCha20-Poly1305. Subsequent sub-phases extend the surface: 1.1.B.3 signature + KEM (Ed25519 + X25519 + P-256), 1.1.B.4 HKDF + HMAC + Argon2id.

* **`pulsar-kernel::crypto::aead`** — typed safe wrappers over `EverCrypt_AEAD_*` FFI declarations. `AeadKey::new(algo, key)` allocates the EverCrypt state holding expanded round keys (AES-GCM) or per-key pre-computation (ChaCha20-Poly1305). `AeadKey::encrypt(nonce, aad, plaintext) -> Vec<u8>` returns `ciphertext || tag` packed in a single allocation. `AeadKey::decrypt(nonce, aad, ciphertext_with_tag) -> Vec<u8>` verifies the tag in constant time and returns the recovered plaintext (or `Error::AeadAuthFailed` on tampered input).
* **`AeadAlgorithm` enum** is `#[non_exhaustive]` and includes only AES-128-GCM, AES-256-GCM, ChaCha20-Poly1305. AES-128-CCM, AES-256-CCM, AES-128-CCM8, AES-256-CCM8 are exposed by the underlying HACL\* dispatcher but deliberately excluded — Pulsar standardises on GCM and ChaCha20-Poly1305 for the regulated-domain compliance surface (NIST SP 800-38D and RFC 8439); CCM variants land only if a specific regulatory framework requires them.
* **Nonce length fixed at 12 bytes** for all three algorithms. ChaCha20-Poly1305 mandates 12 bytes per RFC 8439; AES-GCM permits other lengths but 12 is the FIPS-recommended length per NIST SP 800-38D § 5.2.1.1. Pulsar enforces 12 bytes uniformly so callers can write algorithm-agnostic code. Tag length is fixed at 16 bytes for all three.
* **Module-level documentation flags nonce-reuse as catastrophic** (per the AEAD security model — repeated `(key, nonce)` pairs leak plaintext XOR and enable forgery in GCM). The wrapper enforces only length checks; nonce-uniqueness invariants are wired up at the protocol level by Sprint 1.6 capability tokens and Sprint 2.5 session adapters.
* **Lifecycle**: `AeadKey` holds a `NonNull<EverCrypt_AEAD_state_s>` heap-allocated by `EverCrypt_AEAD_create_in`; `Drop` calls `EverCrypt_AEAD_free` (HACL\* zeroes the expanded state internally per F\* secret-independence postcondition). `Send` is impl'd with explicit SAFETY comment justifying single-owner lifecycle (encrypt/decrypt take `&self` because the state is read-only after creation; `Sync` not impl'd because HACL\* documentation does not guarantee concurrent-call safety on a shared state). Manual `Debug` impl prints only the algorithm field, omitting the FFI state pointer.
* **Error mapping** — `EverCrypt_Error_AuthenticationFailure` (status 3) maps to `Error::AeadAuthFailed`; `InvalidIVLength` (status 4) maps to `Error::InvalidNonceLength` (defensive — should be unreachable since the wrapper rejects wrong-length nonces before the FFI call); other status codes map to `Error::Hacl { source: UnknownStatus(rc) }` for diagnostic logging. The wrapper enforces length validation client-side so the FFI layer should only ever return Success or AuthenticationFailure.

**Tests** (14 new tests across two integration test files):

* **`tests/aead_kat.rs`** — 9 known-answer tests verifying canonical specifications:
  - AES-128-GCM Test Cases 1 + 2 from NIST SP 800-38D Annex B
  - AES-256-GCM Test Case 7 from NIST SP 800-38D Annex B
  - ChaCha20-Poly1305 example from RFC 8439 § 2.8.2 ("Ladies and Gentlemen of the class of '99")
  - Wrong-length key rejection (15 / 17 bytes vs expected 16) → `Error::InvalidKeyLength`
  - Wrong-length nonce rejection (11 / 13 bytes vs expected 12) → `Error::InvalidNonceLength`
  - Truncated ciphertext rejection (10 bytes < tag length 16) → `Error::InvalidOutputLength`
  - Algorithm constants verification (`key_len`, `nonce_len`, `tag_len`)
  - `AeadKey` Debug impl redacts state pointer
* **`tests/aead_property.rs`** — 5 property tests using `proptest`:
  - Encrypt/decrypt round-trip recovers original plaintext
  - Single-bit flip in ciphertext → `Error::AeadAuthFailed` (validates tag mechanism)
  - AAD tampering between encrypt and decrypt → `Error::AeadAuthFailed`
  - Distinct keys produce distinct ciphertexts (probabilistic correctness)
  - Distinct nonces produce distinct ciphertexts under same key
  
  Inputs span 0-1024-byte plaintexts + 0-128-byte AAD across all three algorithms; per-test default is 256 cases, totalling ~3840 random inputs across all properties.

Quality gates verified locally:
  - cargo fmt --all -- --check ✓
  - cargo clippy -p pulsar-kernel --all-targets -- -D warnings ✓
  - cargo nextest run -p pulsar-kernel (42/42 PASS, +14 from this phase) ✓

### Sprint 1.1 — kernel crypto (Phase 1.1.B.1: hash family safe wrappers — merged 2026-04-28 as `799e3531`)

First sub-phase of Phase 1.1.B (safe Rust wrappers over the HACL\* FFI). Lands `pulsar-kernel::crypto` module with the hash family — SHA-2 (256/384/512), SHA-3 (256/384/512), BLAKE2b (64-byte), BLAKE2s (32-byte). Subsequent sub-phases extend the surface: 1.1.B.2 AEAD, 1.1.B.3 signature + KEM (Ed25519 + X25519 + P-256), 1.1.B.4 HKDF + HMAC + Argon2id.

* **`pulsar-kernel::crypto::hash`** — typed safe wrappers over `EverCrypt_Hash_Incremental_*` FFI declarations. Two API surfaces: one-shot `hash(algo, input) -> Vec<u8>` for in-memory data, and streaming `Hasher::new(algo).update(chunk).finalize()` (or non-consuming `.digest()`) for chunked input. Convenience aliases (`sha256`, `sha384`, `sha512`, `sha3_256`, `sha3_384`, `sha3_512`, `blake2b512`, `blake2s256`) return fixed-size stack arrays — the underlying `hash_into_slice` writes directly into a caller-provided buffer so no `Vec<u8>` heap allocation occurs on the hot path. `Hasher::reset()` returns the state to the initial empty-input position without freeing the FFI state (long-running services hashing many small messages with the same algorithm).
* **`HashAlgorithm` enum** is `#[non_exhaustive]` and explicitly omits MD5 + SHA-1. The C-level symbols for those deprecated hashes are linked into the static archive (per ADR-0009 amendment) only because EverCrypt's runtime dispatcher requires them; the Rust public API rejects them at the type system level since no enum variant maps to either. SHA-2-224 + SHA-3-224 are also omitted as Pulsar standardises on the 256/384/512-bit security levels.
* **`EverCrypt_AutoConfig2_init`** is invoked exactly once per process via `std::sync::Once`. HACL\*'s contract guarantees idempotence on repeated calls; the `Once` wrapper avoids redundant atomic synchronisation on the hot path. The CPU-feature dispatcher initialises lazily on first hash invocation.
* **`Hasher` lifecycle**: heap-allocated FFI state held in `NonNull<EverCrypt_Hash_Incremental_state_t>`; freed automatically in `Drop` (whether normal or panic-unwind path). `Send` is implemented (single-owner lifecycle, no internal sharing); `update(&mut self)` prevents concurrent access via the borrow checker.
* **Per-crate lints override**: `pulsar-kernel/Cargo.toml` declares its own `[lints.rust]` + `[lints.clippy]` because the workspace `unsafe_code = "forbid"` policy cannot be applied at the FFI boundary. Override sets `unsafe_code = "deny"` (vs `forbid`) crate-wide so non-FFI sub-modules (audit, session, router, middleware) still reject `unsafe`; the `crypto::hash` module file overrides with `#![allow(unsafe_code)]` at the FFI boundary, with `SAFETY:` comments per call site documenting pre/post-conditions against HACL\*'s contract. `doc_markdown = "allow"` follows the same rationale as `pulsar-crypto-hacl-bindings` (heavily-technical surface; backticking every algorithm name + standard reference would harm readability).
* **Workspace `Cargo.toml`** adds `hex = "0.4"` to `[workspace.dependencies]` (used by KAT tests for hex-decoding the FIPS / RFC test vectors).
* **`pulsar-kernel/Cargo.toml`** drops `ring = { workspace = true }` (per ADR-0009: kernel crypto migrates from `ring` to HACL\* via FFI) and adds `pulsar-crypto-hacl-bindings = { path = "../pulsar-crypto-hacl-bindings", version = "=0.0.1-alpha.0" }` as the new crypto-primitive source. `subtle` + `zeroize` + `secrecy` + `thiserror` + `tracing` retained.

**Tests** (25 new tests across two integration test files):

* **`tests/hash_kat.rs`** — 17 known-answer tests verifying the canonical FIPS / RFC test vectors:
  - SHA-2-256/384/512 of "" + "abc" against FIPS 180-4 § B.1 (6 tests)
  - SHA-3-256/384/512 of "" + "abc" against FIPS 202 (6 tests)
  - BLAKE2b (64-byte) + BLAKE2s (32-byte) of "" + "abc" against RFC 7693 Appendix A (4 tests)
  - Convenience-alias parity vs the agile API (1 test exercising all 8 aliases)
  Each KAT asserts all three API surfaces (one-shot + streaming-single-chunk + streaming-split-chunks) for the same input/output pair so a regression in any path fails loudly.
* **`tests/hash_property.rs`** — 6 property tests using `proptest`:
  - Output length matches `algo.digest_len()` for every supported algorithm
  - Determinism across repeated invocations
  - Streaming single-chunk equals one-shot
  - Streaming two-chunks equals one-shot regardless of split point
  - Streaming per-byte updates equal one-shot (adversarial fragmentation)
  - Distinct inputs yield distinct digests (weak collision-resistance hint)
  Inputs span 0-4096 bytes; per-test default is 256 cases, totalling ~12 800 random inputs across all properties.

Quality gates verified locally:
  - cargo fmt --all -- --check ✓
  - cargo clippy -p pulsar-kernel --all-targets -- -D warnings ✓
  - cargo nextest run -p pulsar-kernel (25/25 PASS) ✓

### Sprint 1.1 — kernel crypto (Phase 1.1.A: HACL\* selective vendoring + bindgen + cc build — merged 2026-04-28 as `1e187504`)

First implementation phase of Sprint 1.1: vendors the HACL\* C distribution at the pinned upstream commit, sets up the per-target build pipeline (cc::Build + bindgen), and validates the FFI surface end-to-end via a FIPS 180-4 SHA-256 test vector. No safe Rust wrappers, Creusot contracts, or TLA+ specs yet — those land in Phases 1.1.B / 1.1.D.

* **HACL\* upstream pin: commit `504c2987452f87fe44bce9b9f12e19d6e051761f`** (2026-04-10, F\* 7b34738 + Karamel 254e099 + Vale 0.3.19) per Decision 2.57 SLSA Level 4 reproducibility. Rationale: latest HACL\* mainline at the time of Sprint 1.1.A initial vendoring; chosen over a release tag because HACL\* upstream releases lag mainline by 6-12 months and the 2026Q2 mainline includes the canonical configure-script feature detection logic mirrored in our `build.rs`.
* **Vendored under `crates/pulsar-crypto-hacl-bindings/hacl-c/`** — 259 files / 5.0 MB total. Selective vendoring per Decision 2.60: `dist/gcc-compatible/` (sources + headers + Vale ASM for x86_64 Linux/macOS/Windows + powerpc64le) + `dist/karamel/include/` (KaRaMeL runtime headers) + `dist/karamel/krmllib/dist/minimal/` (F\* runtime shims). Layout: `hacl-c/src/` (85 .c + 17 .S + 5 .asm), `hacl-c/include/` (98 top-level + 37 internal headers), `hacl-c/include/krml/` + `hacl-c/include/krmllib/dist/minimal/` (karamel headers). Files outside the curated compile list (Frodo PQC, HPKE, K256 ECDSA, NaCl, RSA-PSS, Salsa20, MD5, SHA-1, DRBG, FFDHE, debug helpers) remain on disk for audit clarity but never enter the link surface.
* **Reproducibility manifest: `hacl-c/HACL_VERSION` + `hacl-c/MANIFEST.sha256`.** HACL_VERSION captures the upstream repo + commit + commit date + extracted-subtree provenance + toolchain versions (F\*, Karamel, Vale) + vendoring date + license. MANIFEST.sha256 records the SHA-256 of every vendored file (.c / .h / .S / .asm) sorted lexicographically. Anyone can verify the vendored tree is authentic by re-running `tools/scripts/vendor-hacl.sh` and confirming the resulting MANIFEST.sha256 matches byte-for-byte.
* **`tools/scripts/vendor-hacl.sh` re-vendoring procedure** — clones HACL\* upstream at the pinned commit, copies the curated subset, regenerates HACL_VERSION + MANIFEST.sha256. Idempotent: re-running on an already-vendored tree must produce a byte-identical result. Argument-overridable for future re-vendoring (`vendor-hacl.sh <new-commit-sha>` followed by manual review of the diff before commit).
* **`build.rs` per-target compile pipeline** restructured into three `cc::Build` instances: portable (libhacl_pulsar.a, ~50 .c files + Vale ASM for x86_64), SIMD-128 (libhacl_pulsar_simd128.a, 7 sources with `-mavx`), SIMD-256 (libhacl_pulsar_simd256.a, 8 sources with `-mavx -mavx2`). Per-target `config.h` generation deterministically derives feature flags (TARGET_ARCHITECTURE, HACL_CAN_COMPILE_INTRINSICS / VALE / INLINE_ASM / VEC128 / VEC256 / UINT128) from `(target_arch, target_os)` rather than running HACL\*'s host-based `configure` probes — preserves SLSA L4 reproducibility (every supported target produces a fixed config.h independent of host CPU features). x86_64 Linux/macOS/Windows: full feature set (Vale + AVX2 + 128-bit intrinsics). aarch64: NEON 128-bit, no Vale, no AVX2. x86 32-bit: portable C only. powerpc64: portable C only on first pass (Vale ppc64le ASM available but not enabled this phase).
* **Curated source list — 50 .c files for the 12 classical primitives** (per Decision 2.60). Excludes: Frodo (5 .c), HPKE (15 .c), K256 ECDSA + EC (2 .c), NaCl (1 .c), RSA-PSS + Bignum32/4096/4096_32 (4 .c), Salsa20 (1 .c), DRBG (2 .c), FFDHE (1 .c). Two deprecated hashes (MD5, SHA-1) included only because EverCrypt_Hash.c's runtime dispatcher references their `_oneshot` symbols — the safe wrappers in `pulsar-kernel::crypto` will not expose these algorithms to downstream consumers.
* **Bindgen FFI declarations** scoped to the 12 primitives' public APIs via allowlist (`EverCrypt_AEAD_*`, `EverCrypt_Curve25519_*`, `EverCrypt_Ed25519_*`, `EverCrypt_HKDF_*`, `EverCrypt_HMAC_*`, `EverCrypt_Hash_*`, `EverCrypt_AutoConfig2_*`, `EverCrypt_Chacha20Poly1305_*`, `EverCrypt_Poly1305_*`, `Hacl_P256_*`, `Hacl_Streaming_HMAC_*`, `Lib_Memzero0_*` plus the `Spec_Hash_Definitions_*` / `Spec_Agile_Cipher_*` / `Spec_Agile_AEAD_*` / `EverCrypt_Error_*` algorithm-id enums and error codes). 93 functions exposed; everything else from the vendored headers is excluded from the generated `bindings.rs`. Generated bindings live in `OUT_DIR/bindings.rs` and are included from `src/lib.rs` under `pub mod ffi`. The wrapper umbrella header lives in `OUT_DIR/hacl_pulsar_wrapper.h` (also generated by build.rs).
* **Per-crate `[lints.rust]` + `[lints.clippy]`** override the workspace policy: this `*-sys`-style binding crate sets `unsafe_code = "allow"` (FFI declarations are inherently `unsafe extern "C"`), `doc_markdown = "allow"` (heavily technical doc surface — backticking every algorithm name + arch identifier would harm readability without security benefit), and `unwrap_used` / `expect_used` / `type_complexity` allowed at the build.rs file level (build scripts panic-on-setup-failure is idiomatic; the Vale ASM dispatch table is build-time configuration data). Hygiene lints (missing_docs, unused_must_use, rust_2024_compatibility, unreachable_pub, etc.) are inherited explicitly so this crate stays aligned with the rest of the workspace.
* **End-to-end FFI smoke test** (`tests/smoke.rs::sha256_empty_input_matches_fips_180_4_test_vector`) — initialises the EverCrypt CPU feature dispatcher (`EverCrypt_AutoConfig2_init`), computes SHA-256 of empty input via `EverCrypt_Hash_Incremental_hash`, validates the output matches the FIPS 180-4 § B.1 canonical test vector (`e3b0c4...b855`). Confirms the full pipeline works: vendored C compiled cleanly (3 archives totalling ~10.7 MB linked), bindgen declarations linked correctly, runtime CPU dispatcher initialises, single-shot hash returns the expected 32-byte digest. Also includes the standard four smoke tests per crate (VERSION non-empty, VERSION matches CARGO_PKG_VERSION, Result alias resolves, prelude module importable).
* **Workspace `clippy::lint_groups_priority` workspace fix** bundled in: `rust_2024_compatibility = "deny"` (string-literal level, implicit priority 0) was colliding with individual lints in `[workspace.lints.rust]` per `rust_2024_compatibility` clippy diagnostic. Fixed by setting explicit priority: `rust_2024_compatibility = { level = "deny", priority = -1 }`. Restores `cargo clippy --workspace` ability to run the actual lint check (was blocking on the parse error before reaching any lint).

This phase delivers the FFI substrate. Phase 1.1.B (safe Rust wrappers + Creusot contracts) consumes the FFI surface this phase exposes. Phase 1.1.C (libcrux PQC + hybrid constructions) adds the PQC primitives in parallel.

### Sprint 1.1 — kernel crypto (Phase 1.1.0: multi-source verification stack amendment — merged 2026-04-27 as `ca9a54b7`)

Pre-implementation amendment landed on `feat/sprint-1-1-crypto` branch before any vendoring or code work. An upstream-state audit during Sprint 1.1 kickoff established that **HACL\* upstream as of 2026Q2** (commit `504c298` dated 2026-04-10) **does not** ship NIST ML-KEM-768/1024 or ML-DSA-65/87 — the post-quantum surface in HACL\* is limited to Frodo (a non-NIST scheme) and K256 ECDSA. The initial v2.3 lock-in (Decision 2.53 + ADR-0009 + ADR-0012) stated otherwise; the amendment corrects the sourcing to a multi-source verification stack while preserving the formally-verified posture across all sixteen primitives.

* **Decision 2.60 — Multi-source formally-verified crypto stack: HACL\* (classical) + libcrux (PQC) + RustCrypto (Argon2id).** New Section II decision formalising that the cryptographic surface in `pulsar-kernel::crypto` is sourced from a multi-source stack rather than a single library: HACL\* via FFI (`pulsar-crypto-hacl-bindings`) for the twelve classical primitives (AEAD, X25519, P-256, Ed25519, SHA-2/3, BLAKE2, HKDF, HMAC), libcrux Rust-native for the four NIST PQC primitives (`libcrux-ml-kem` + `libcrux-ml-dsa`), and RustCrypto `argon2` for the password-hashing KDF (audited canonical Rust implementation). All sixteen formally-verified primitives share the same Cryspen / hax / F\* verification provenance — distribution is split (C extraction for HACL\*, Rust extraction for libcrux) but verification toolchain, methodology, and team are unified. Argon2id is the only audited-but-not-verified primitive in the stack and is explicitly scoped to password hashing where audit-level assurance is industry-standard.
* **ADR-0009 amended** to scope the HACL\* FFI surface to the twelve classical primitives only. Removes ML-KEM/ML-DSA rows from the HACL\*-coverage table; adds a "Primitives sourced from companion crates" section pointing to libcrux (PQC) + RustCrypto (Argon2id). Updates Consequences (positive: PQC primitives via libcrux retain Cryspen / hax / F\* provenance; negative: clarifies the ~10-15 MB selective vendoring footprint after dropping the unused PQC C extractions). Updates Alternatives considered to add three new rejected paths: HACL\*-only sourcing (rejected: HACL\* upstream lacks NIST PQC), libcrux-for-everything (rejected: HACL\* production deployment evidence unmatched), and clarifies the original liboqs alternative against libcrux's stronger verification posture. Status field annotated "Accepted (amended 2026-04-27 per Decision 2.60)".
* **ADR-0012 amended** to specify that ML-KEM-768 + ML-DSA-65 are sourced from libcrux Rust-native crates per Decision 2.60. Updates Context to acknowledge the HACL\* upstream coverage gap. Updates Decision body with explicit per-primitive sourcing (X25519 + Ed25519 from HACL\* via FFI; ML-KEM-768 from `libcrux-ml-kem`; ML-DSA-65 from `libcrux-ml-dsa`). Updates Positive consequences. Updates References to add libcrux upstream + crates + Cryspen hax tool URLs. Status field annotated "Accepted (amended 2026-04-27 per Decision 2.60)".
* **plan.md Section IV.4.2.5** (`pulsar-crypto-hacl-bindings` per-crate spec) re-scoped to twelve classical primitives. Drops ML-KEM/ML-DSA from the wrapped-primitive list. Adds an explicit "Primitives NOT wrapped here (sourced elsewhere)" subsection for libcrux + Argon2id.
* **plan.md Section XII** updated: the executive-summary success-metric line restructured from "sixteen formally-verified cryptographic primitives sourced from HACL\* via FFI" to "sixteen formally-verified cryptographic primitives sourced from a multi-source verification stack: twelve classical from HACL\* via FFI and four PQC from libcrux Rust-native — all sharing Cryspen / hax / F\* verification provenance"; the per-row table updated with the multi-source language; the PQC posture row updated to acknowledge libcrux for ML-KEM/ML-DSA.
* **plan.md Section 14.1** (post-quantum cryptography phasing) updated to reference Decision 2.60 sourcing — `libcrux-ml-kem` + `libcrux-ml-dsa` as PQC dependencies, HACL\* C distribution for X25519 + Ed25519 only. Drops the obsolete `pqcrypto-*` ecosystem dependency note (libcrux supersedes).
* **Workspace `Cargo.toml`** adds `libcrux-ml-kem = "0.0.8"` and `libcrux-ml-dsa = "0.0.6"` to `[workspace.dependencies]`. The crypto-primitives section comment block restructured to document the multi-source sourcing per Decision 2.60.
* **`crates/pulsar-crypto-hacl-bindings/`** scope updated: Cargo.toml description re-scoped to "twelve classical cryptographic primitives", README.md WHAT/WHY sections rewritten to clarify the classical scope + libcrux sourcing for PQC, src/lib.rs crate-level documentation rewritten to enumerate the twelve classical primitives + cross-reference Decision 2.60 + libcrux for the PQC surface.

This amendment is the prerequisite to Phase 1.1.A (HACL\* selective vendoring) and Phase 1.1.C (libcrux PQC integration). No code changes — purely a strategic re-scoping captured in ADRs + plan + workspace dependencies. The amendment commit precedes the FFI vendoring commit so the architecture is locked before any C source lands.

### Sprint 0.9-bis — v2.3 reconciliation (merged 2026-04-27 as `bfd00a43`)

After Sprint 0.9 hardening (commits A-G), an interactive validation pass on the v2.2 audit findings produced **nine new Section II decisions** (Decisions 2.51-2.59) lifting the framework to 100% best-possible / state-of-art. The v2.3 reconciliation lands these decisions in commits H1-H9:

* **Commit H1 — `plan(v2.3): add Decisions 2.51-2.59 + update Section XII metrics`** (`58b8c34`).
  Adds nine new Section II decisions: 2.51 workspace size 76 crates, 2.52 compliance scope 31 frameworks, 2.53 HACL\* via FFI for cryptographic primitives, 2.54 SPARK 2014 invariants module, 2.55 audit chain Ed25519 + Merkle + RFC 6962 transparency log, 2.56 100% MC/DC + 99% mutation kill rate, 2.57 SLSA Source L3 + Build L4 + in-toto, 2.58 hybrid PQC from Sprint 1.1, 2.59 TLA+ scope 15 specifications. Updates Section XII metrics rows + executive summary + Section IV introduction + Section 2.32 + Sprint 0.8 / 0.9-bis narratives.
* **Commit H2-H4 (atomic) — `feat(workspace): v2.3 expansion to 76 crates — dé-fusion + sqlx-pattern + HACL\* FFI`** (`ce8e51a`). 223 files changed, 3703 insertions, 285 deletions.
  Three independent decompositions applied to the v2.2 baseline of 53 crates: dé-fusion of the 4 v2.2 meta-crates (pulsar-guard / pulsar-realtime / pulsar-orchestration / pulsar-cluster) back into 14 sub-module crates (+10 net), sqlx-pattern driver split for the 3 multi-backend crates (pulsar-orm + pulsar-cloud + pulsar-storage) adding 12 driver crates (+12 net), HACL\* FFI binding crate `pulsar-crypto-hacl-bindings` (+1 net). Total: 53 + 10 + 12 + 1 = 76 crates. Each new crate gets a qualitative scaffold per Section XVII module layout: tailored Cargo.toml description + per-crate keywords + categories ; README.md with WHAT + WHY + HOW + Status sections ; src/lib.rs with substantive crate-level documentation cross-referencing plan Section IV (per-crate spec) + Section V (sprint) ; src/error.rs with sketched Error enum (3-5 placeholder variants) + thiserror derive + Result<T> alias ; src/prelude.rs re-exporting error types ; src/sealed.rs with the Sealed trait declared per RFC 0445 sealed-trait pattern ; tests/smoke.rs with 4 tests (version constant non-empty + matches CARGO_PKG_VERSION + Result alias type-checks + prelude module importable). Inter-crate dependency rewires + meta-crate re-exports updated. Workspace.dependencies adds aws-config = "1.5".
* **Commit H5 — `feat(spark): services/spark-invariants/ Ada/SPARK 2014 module per Decision 2.54`** (`84fe41f`).
  `services/spark-invariants/` Ada/SPARK 2014 sub-project formally proving capability token unforgeability + audit chain append-only enforcement via GNATprove level 4. Module structure: `pulsar_invariants.gpr` GNAT project file with SPARK Mode (On) + GNATprove proof level 4 + Z3 + CVC5 + Alt-Ergo SMT solvers ; `src/capability_unforgeability.{ads,adb}` packages declaring Capability_Token + Capability_Set abstract types + Mint / Verify functions with Pre/Post contracts + C ABI exports `pulsar_spark_verify_capability` ; `src/audit_chain_append_only.{ads,adb}` packages declaring Merkle_Root + Audit_Entry types + Verify_Append with the uniqueness postcondition + C ABI export `pulsar_spark_verify_audit_append`. Both packages declare contracts at Phase 0; bodies are placeholders that always return "verification declined" pending Sprint 1.2 (capability + audit kernel sprint). The C ABI symbols exist so Rust FFI bindings can link immediately.
* **Commit H6 — `docs(adr): land ADRs 0009-0014 for v2.3 reconciliation lock-ins`** (`f6cd93c`). 7 files, 817 insertions.
  Six new ADRs cover the v2.3 architectural decisions per the interactive validation pass: ADR-0009 HACL\* via FFI for cryptographic primitives (closes "audited" → "formally verified" gap, Mozilla NSS / Linux WireGuard / Tezos / Azure VPN production deployment evidence), ADR-0010 Ada/SPARK 2014 module for capability + audit-chain invariants (formal proof at a tier strictly stronger than Rust + Creusot for cross-function quantified invariants), ADR-0011 dé-fusion + sqlx-pattern split → 76 crates (granular CVE blast-radius + per-crate semver + per-crate audit cycle), ADR-0012 hybrid PQC from Sprint 1.1 (no harvest-now-decrypt-later window — X25519 + ML-KEM-768 KEM + Ed25519 + ML-DSA-65 signatures), ADR-0013 audit chain Ed25519 + Merkle + RFC 6962 transparency log (replaces v2.2 HMAC-SHA256 PHP-parity carry-over, same construction as Sigstore Rekor + CT + Google Trillian), ADR-0014 100% line + branch + MC/DC coverage + 99% mutation kill rate on critical-tier crates (DO-178C Level A coverage criterion + academic state-of-art mutation threshold). INDEX.md updated with ADRs 0009-0014 + roadmap renumbered (old 0009-0093 entries shifted to 0015-0098 + new entries 0099 + 0100 for SLSA Source L3 + in-toto layout implementation).
* **Commit H7 — `docs(compliance): land compliance matrix with 31 frameworks (v2.3 +8)`** (`d5499ef`).
  `docs/compliance/matrix.md` is the canonical mapping from regulatory clauses to implementing crates + decisions + ADRs + test/audit evidence. Per Decision 2.52 v2.3 lock-in: 30 mandatory + 1 opt-in (MiCA) = 31 total. Eight v2.3 NEW frameworks each get a dedicated mapping section: NYDFS Part 500 (US NY financial services), MAS TRM (Singapore banking + insurance), APRA CPS 234 (Australian banking + insurance + super), OSFI B-13 (Canadian federal financial institutions — NIST CSF aligned), RBI Cybersecurity Framework (Indian banking — 51 baseline controls + C-SOC + incident reporting), Quebec Law 25 (Canadian-francophone privacy), Switzerland nFADP (Swiss data protection — in force since 1 Sept 2023), Common Criteria EAL 6+/7 (formal-verification product certification target — Pulsar provides CC-evaluation-ready artefacts so downstream applications can submit). Cross-framework synergies table identifies controls satisfying multiple frameworks: Ed25519+Merkle+RFC 6962 audit chain covers 10 frameworks; HACL\*-verified crypto covers 9; capability-token unforgeability covers 6 + Common Criteria ADV_TDS.6/7; RtbF two-phase commit covers 7 privacy frameworks; hybrid PQC covers 7 forward-quantum posture frameworks. Framework certification + attestation pathways table identifies which frameworks Pulsar Foundation pursues at GA (ISO 27001:2022, SOC 2 Type II, NIST FIPS 140-3 L2, OpenSSF Gold + Scorecard, OpenChain ISO/IEC 5230) vs which it enables for downstream applications without pursuing itself (PCI-DSS, HIPAA, Common Criteria EAL 6+/7).
* **Commit H8 — `ci(supply-chain): SLSA Source L3 + in-toto layout extension per Decision 2.57`** (`9fe32a7`).
  Lift the supply-chain attestation pipeline from SLSA Build L3 + Cosign + SBOM (v2.2 baseline) to SLSA Source L3 + Build L4 + in-toto layout + Cosign + SBOM (v2.3 lock-in per Decision 2.57). Three independent attestation layers cover the full source → build → SBOM → sign → publish chain: (1) SLSA Source Level 3 — branch protection + signed commits + 18-month signing-key audit retention, attested via a textual file capturing the signing identity of every commit reachable from the release tag + branch protection state + workflow_ref + job_workflow_sha (Cosign-signed via Sigstore keyless) ; (2) SLSA Build Level 4 — hermetic build + reproducible-build verification (cross-builder digest match per repro-build.yml) + provenance attestation via actions/attest-build-provenance@v1 ; (3) in-toto layout — declares the full source → build → SBOM → sign → publish chain with expected signer + expected output per step, signed Cosign keyless. Federated mirror to Sigstore Rekor: every Cosign keyless sign operation publishes a transparency-log entry automatically. publish.yml ORDER list updated to v2.3 76-crate workspace. docs/ops/ci.md extended with SLSA Source L3 + in-toto layout + downstream-consumer verification recipe sections.
* **Commit H9 — `docs(release): v2.3 final alignment — README + CHANGELOG + Sprint 0.9-bis closure`** (this commit).
  README.md updated to reflect v2.3 architecture: 76 first-party Rust crates (per Decision 2.51), 15 TLA+ specifications (per Decision 2.59), 31 compliance frameworks (per Decision 2.52), HACL\*-formally-verified cryptographic primitives (per ADR-0009), 2 SPARK-formally-proven invariants (per ADR-0010), 100% MC/DC + 99% mutation kill rate (per ADR-0014), hybrid PQC from Sprint 1.1 (per ADR-0012), Ed25519 + Merkle + RFC 6962 audit chain (per ADR-0013), SLSA Source L3 + Build L4 + in-toto attestation (per ADR-0011 + Decision 2.57). Workspace layout section restructured to enumerate the 76 crates by layer with the 14 dé-fusion crates + 12 sqlx-pattern driver crates + HACL\* FFI binding crate explicitly called out. Mermaid architecture diagram updated to reflect the un-fused realtime + un-fused security controls + the SPARK invariants module under Kernel.

The Sprint 0.9-bis closure prepares the `v0.0.2-alpha.0` namespace-reservation publish (76 crates representing the v2.3 architecture). The prior `v0.0.1-alpha.0` tag remains preserved on commit `fe2332a` as the historical first publication (53 crates representing v2.2 architecture).

### Sprint 0.9 — Phase 0 hardening (folded into Sprint 0.9-bis merge `bfd00a43`)

* **Commit A — workspace tooling baseline:** `.gitattributes` enforcing LF on all text files (SLSA 4 reproducibility prerequisite); `.editorconfig` for cross-IDE consistency (4-space Rust, 2-space YAML/JSON, tab Go); `[workspace.lints]` table in root `Cargo.toml` centralising rust + clippy + rustdoc lint policy (Cargo 1.74+ workspace-lint feature, eliminating per-crate `#![deny(missing_docs)]` + `#![forbid(unsafe_code)]` repetition); `tools/xtask/` workspace member with five subcommand stubs (`adr-index`, `api-surface`, `quality-gate`, `repro-build`, `publish-order`); `tools/scripts/regenerate-adr-index.sh` and `regenerate-api-surface.sh` placeholders.
* **Commit B+C — Section XVII module layout + scaffolds + repo config:** every crate in `crates/pulsar-*/src/` gains `prelude.rs` + `error.rs` + `sealed.rs` per Section XVII catalogue; every crate gains `tests/smoke.rs` (two tests verifying `VERSION` is non-empty and matches `CARGO_PKG_VERSION`); every crate `Cargo.toml` gains `[lints] workspace = true`; `spec/README.md` documents the nine planned TLA+ specifications; `examples/` gains 10 example skeletons and a top-level README; `pulsar-cli` gains `src/main.rs` + `[[bin]] name = "pulsar"` so the binary entry exists; `.github/CODEOWNERS` auto-assigns `@LennyObez` to critical-coverage-tier crates + `spec/` + `docs/plan.md` + `docs/adr/` + CI configuration; `.github/dependabot.yml` weekly Cargo + GitHub Actions + Go + npm dependency scanning with patch+minor grouped + major individual; `.github/FUNDING.yml` registers GitHub Sponsors per Decision 2.49; `.github/ISSUE_TEMPLATE/security.yml` redirects security reporters to the private GitHub Security Advisory channel.
* **Commit C-fixup — Cargo.lock refresh:** lockfile regenerated to register the new `tools/xtask` workspace member, required for SLSA Level 4 two-builder reproducibility determinism.
* **Commit D — supply-chain hardening (3 new workflows + publish.yml extension):** `.github/workflows/actionlint.yml` runs `raven-actions/actionlint@v2` on every workflow change for YAML+expression+shellcheck validation; `.github/workflows/cross-platform.yml` 5-target matrix (`x86_64-unknown-linux-gnu`, `x86_64-unknown-linux-musl`, `aarch64-apple-darwin`, `x86_64-apple-darwin`, `x86_64-pc-windows-msvc`) verifying `cargo check --workspace` on every push + `cargo test --workspace` on every native target per plan Section 16.10.6 cross-platform binary distribution; `.github/workflows/repro-build.yml` two-builder matrix (a + b) running `cargo build --release --package pulsar-cli --bin pulsar` from clean checkouts on isolated runners + `compare-digests` job that fails the build when SHA-256 digests diverge per plan Section 14.6 SLSA Level 4 reproducible-build smoke test; `publish.yml` extended with the `supply-chain` job: CycloneDX SBOM via `cargo cyclonedx --format json --override-filename pulsar-framework`, Cosign keyless signing via `sigstore/cosign-installer` + `cosign sign-blob` against Sigstore Fulcio + Rekor transparency log, SLSA Level 3 build provenance attestation via `actions/attest-build-provenance@v1`. `docs/ops/ci.md` extended with the three new workflow rows in the inventory table + a "Supply-chain pipeline" section + a "Reproducible-build verification" section + a workflow-action SHA-pinning roadmap note. Total now eight workflows.
* **Commit E — docs structure scaffolds:** `docs/api-surface.md` initial version documenting the visibility-tier system (`stable` / `experimental` / `deprecated`), the per-layer crate inventory (currently all empty), and the `cargo public-api`-driven regeneration procedure that lands at Sprint 0.10; `docs/perf/baselines/README.md` documenting the criterion baseline archive layout, the 10-subsystem benchmark catalogue, the 5% regression-detection threshold, the dedicated self-hosted runner caveat, and the immutable-baseline policy; `docs/observability/dashboards/README.md` documenting the 20 production-ready Grafana dashboards target (Section XII success metric), the per-dashboard required panel set (saturation / error / latency / throughput / subsystem-specific / annotations / alert pointer), the validation pipeline that lands at Sprint 1.0; `docs/book/book.toml` mdBook configuration with rust theme + linkcheck + toc + mermaid + admonish preprocessors + `create-missing = true` Phase 0 posture; `docs/book/src/SUMMARY.md` ~80-chapter narrative-doc roadmap covering Parts I-IX (getting started + concepts + guides + recipes + ops + compliance + migration + contributing + reference); `docs/book/src/introduction.md` framing chapter; `docs/security/threat-model.md` STRIDE skeleton with eight named trust boundaries (T1-T8) + per-layer placeholder catalogues (lit by the corresponding sprint when each layer ships) + cross-cutting supply-chain + cryptographic-agility + insider-threat sections + review cadence + acknowledgements (NIST SP 800-30, ISO/IEC 27005, OWASP ASVS L3, OWASP API + LLM Top 10:2023, CHERI worksheet, seL4 paper).
* **Commit F — reproducible dev environment:** `flake.nix` Nix flake declaring the canonical pinned dev shell using `oxalica/rust-overlay` reading `rust-toolchain.toml` + the full quality-gate plugin set + mdBook + supply-chain tooling (cosign, syft, grype, trivy) + TLA+ tools + per-platform conditionals (macOS frameworks, Linux io_uring/eBPF libraries) — gives every contributor + CI runner + air-gapped audit environment a byte-identical toolchain (SLSA Level 4 reproducibility prerequisite); `.devcontainer/devcontainer.json` mirrors the Nix flake for VS Code Remote Containers + GitHub Codespaces + Coder + GitPod + JetBrains Gateway with the same toolchain + plugin set + Nix support so cross-validation against `flake.nix` works inside the container + 6 forwarded ports + 14 VS Code extensions; `.devcontainer/post-create.sh` idempotent first-boot bootstrap installing cargo plugins via `cargo binstall` (faster than source-build) + mdBook preprocessors + cosign + syft + actionlint + pre-commit hooks + opportunistic GPG signing config when a key already exists for the user's git email; `CITATION.cff` Citation File Format 1.2.0 metadata (authors, version, date-released, license, repository, keywords, full reference list pointing at the academic + canonical inspirations the framework draws from — seL4, CompCert, TLA+, LMAX Disruptor, SQLite, Erlang/OTP, SLSA, OWASP ASVS L3 + API + LLM Top 10:2023, NIST SP 800-30, ISO/IEC 27005); `.well-known/security.txt` RFC 9116 disclosure entry-point (3 contact channels, expiry 2027-04-25, PGP encryption pointer, acknowledgments link, preferred languages en+fr, canonical URLs, policy pointer to `SECURITY.md`).
* **Commit G — ADR consistency: Compliance mapping section in 5 more ADRs.** Brings the section count from 3-of-8 (0004 + 0005 partial + 0008) to 8-of-8 (0001 + 0002 + 0003 + 0005 full + 0006 + 0007 added; 0004 + 0008 already present). Each new section enumerates the regulatory clauses the decision satisfies — for ADR-0001 (Rust rewrite) the mapping covers ISO 27001 A.8.28 secure coding + ISO 27034-1 + ISO 25010 + NIST SSDF PW.1 + NIST SP 800-53 SA-15/SI-16 + BSI TR-03145 + EU CRA Annex I + CISA memory-safe roadmap; for ADR-0002 (modular monolith + hexagonal) the mapping covers ISO 27001 A.5.23/A.8.30 + ISO 27017 + GDPR Art. 25/28 + HIPAA 164.308(a)(4) + PCI-DSS 6.2.4 + DORA Art. 9 + NIS2 Art. 21(2)(d) + EU AI Act Art. 9; for ADR-0003 (microkernel formal verification) the mapping covers Common Criteria EAL 6+/7 + NIST FIPS 140-3 + ISO 27001 A.8.27 + ISO 27034-3 + PCI-DSS 6.2.3.1 + PSD2 RTS Art. 4 + DORA Art. 9(2)/26-27 + EU AI Act Art. 15 + NIST SP 800-160; for ADR-0005 (Apache-2.0 + EUIPO) the mapping covers ISO 5230 OpenChain + ISO 18974 + ISO 5962 SPDX + EU CRA Annex II + EU AI Act Art. 25 + GDPR Art. 28(3) + OpenSSF gold tier + WIPO Madrid Protocol; for ADR-0006 (crates.io namespace + meta-crate) the mapping covers SLSA L4 + NIST SSDF PS.1/PS.2/PS.3 + EU CRA Annex I + NIS2 Art. 21(2)(j) + OpenSSF SCM + ENISA OSS guidelines; for ADR-0007 (branch model) the mapping covers ISO 27001 A.8.32/A.5.4/A.8.4 + ISO 27034-3 + PCI-DSS 6.5.1-6.5.4 + DORA Art. 9(2)(c) + NIST SSDF PS.1/PS.2 + NIST SP 800-53 CM-3/CM-5 + EU CRA Annex I + NIS2 Art. 21(2)(e).

### Sprint 0.8 — v0.0.1-alpha.0 namespace-reservation publish (deferred to push)

* All 53 crate `README.md` files standardised to the spec wording: "**Status:** Placeholder release for namespace reservation. The implementation ships in 0.1.0. See [`docs/plan.md`](../../docs/plan.md) Section IV for the per-crate spec and Section V for the implementing sprint."
* Annotated GPG-signed tag `v0.0.1-alpha.0` created locally on `develop` (HEAD) — annotated per plan Section VIII.3 tag-naming convention; signed with the maintainer's Ed25519 key per Decision 2.30.
* **Push + crates.io publish deferred to explicit user authorisation** per CLAUDE.md operator-manual rule "Never push unless the user explicitly requests it." Once the user authorises, the publish flow is:
  1. `git push origin develop` — push the develop branch to the GitHub remote.
  2. `git push origin v0.0.1-alpha.0` — push the annotated tag, which triggers `.github/workflows/publish.yml`.
  3. The `publish.yml` Trusted Publisher OIDC step authenticates against crates.io (requires the repo's `crates-io` environment to be configured with OIDC trust on crates.io — a one-time setup).
  4. The dependency-ordered publish step iterates the 53-crate `ORDER` array and runs `cargo publish --package <crate> --no-verify` for each, ending with `pulsar-framework` (meta-crate).
  5. The `github-release` job extracts release notes from `CHANGELOG.md` and creates a GitHub Release with `prerelease: true` (because the tag contains `-`).
* Phase 0 closure tag — Phase 1 (kernel) starts on the next commit on `develop`.

### Sprint 0.7 — Plan committed + trademark policy

* `docs/plan.md` v2.2 already committed (throughout Phase 1-11 reconciliation + Sprint 0.1-0.6 refinements); the master plan is the authoritative reference for every Phase 0 decision.
* `docs/trademark-policy.md` lands the EUIPO-aligned trademark policy per [ADR-0005](docs/adr/0005-apache-2-0-licence.md) and [Decision 2.50](docs/plan.md). Sections cover what the trademark covers, nominative use that requires no permission, uses that require permission, fork rules, downstream-application rules, commercial-entity rules (consulting, training, hosting, managed services, post-GA dual-licence path), defensive patent posture, reporting concerns, and contact channels. Modelled on Linux Foundation, Rust Foundation, PostgreSQL, and Apache Software Foundation trademark policies.
* README.md already references both files (Status link to plan; License + Trademark section link to trademark policy).

### Sprint 0.6 — Architecture diagrams (6 Mermaid)

* `docs/architecture/overview.md` — entry point linking the six diagrams + ADR roadmap + plan section cross-references.
* `docs/architecture/diagrams/01-layered.md` — twelve-layer composition + dependency rule + event bus cross-cuts + WASM sandbox at the periphery.
* `docs/architecture/diagrams/02-crate-dependency-graph.md` — 53-crate DAG, hub crates (kernel, audit, orm, http), consolidation impact (Section 16.14).
* `docs/architecture/diagrams/03-request-lifecycle.md` — sequence diagram tracing `POST /api/v1/posts` through every quality-relevant subsystem with TLA+/Creusot anchors and latency budget.
* `docs/architecture/diagrams/04-event-bus-topology.md` — typed events publishers/subscribers, partitioning, delivery semantics (at-least-once + transactional outbox + at-most-once opt-in), schema evolution.
* `docs/architecture/diagrams/05-wasm-sandbox-isolation.md` — trusted core vs sandbox trust boundary, capability table, crash isolation, performance budget, component-model interop.
* `docs/architecture/diagrams/06-process-model.md` — multi-instance production deployment, stateful tier (PostgreSQL Patroni + Redis + Tantivy + S3 + ClickHouse), external systems, observability flow, deployment topologies (single-tenant, multi-tenant, multi-region, edge, air-gapped).

### Sprint 0.5 — Initial 8 ADRs + INDEX.md

* `docs/adr/0000-template.md` — MADR-style template with Status, Context, Decision, Consequences (positive/negative/neutral), Alternatives considered, References, optional Compliance mapping. Every future ADR uses this template.
* `docs/adr/0001-rewrite-in-rust.md` — Full rewrite of Pulsar Framework from PHP 8.5 to Rust 1.95+ (Decisions 2.1, 2.2, 2.3, 2.7).
* `docs/adr/0002-modular-monolith-hexagonal.md` — Modular monolith with hexagonal ports-and-adapters across crate boundaries (Decisions 2.22, 2.32).
* `docs/adr/0003-microkernel-formal-verification.md` — Formally verified microkernel for crypto, audit, session, router, middleware, DI (Decisions 2.20, 2.22, 2.3) — encodes the nine TLA+ specs + Creusot contract scope.
* `docs/adr/0004-wasm-extension-sandbox.md` — WebAssembly extension sandbox with capability-based security (Decision 2.19) — implementation deferred to Sprint 4.1.
* `docs/adr/0005-apache-2-0-licence.md` — Apache License, Version 2.0 with EUIPO trademark protection (Decisions 2.21, 2.50) — Madrid Protocol international extensions at GA.
* `docs/adr/0006-crates-io-pulsar-namespace.md` — `pulsar-*` crates.io namespace with meta-crate `pulsar-framework` (Decisions 2.23, 2.24) — 53 crates enumerated with re-export discipline.
* `docs/adr/0007-branch-model.md` — Branch model with `main` stable, `develop` integration, sprint feature branches (Decisions 2.5, 2.6) — three-tier topology with required status checks.
* `docs/adr/0008-gitflow-sprint-branches.md` — Sprint feature branch contract `feat/sprint-N-M-topic` with squash-merge (Decisions 2.5, 2.6, 2.30) — full lifecycle, naming convention, status checks, tag policy.
* `docs/adr/INDEX.md` — index of all ADRs plus roadmap of ~85 future ADRs aligned with plan Section V sprint sequence.
* CI invariant: every ADR file referenced in INDEX.md must exist (ci.yml `adr-index` job enforces).

### Sprint 0.4 — CI workflows formal exit gate

* All five GitHub Actions workflows (ci.yml, nightly.yml, audit.yml, benchmark.yml, publish.yml) verified syntactically valid via `python3 -c "import yaml; yaml.safe_load(...)"`.
* New `docs/ops/ci.md` documents the CI topology end-to-end: workflow inventory, per-job breakdown, caching strategy, runner choice, local invocation aliases, and Sprint 0.4 exit verification status.
* The "first ci.yml run on develop succeeds" and "publish.yml dry-runs cargo publish --dry-run" exit criteria are deferred to the first `git push origin develop` (per plan workflow rule "never push unless explicitly requested"). Workflow source is correct and triggers properly on `vN.M.P*` tag patterns.

### Sprint 0.3 — 53 crate stubs formal exit gate

* `cargo check --workspace` succeeds in 2m22s on local develop (exit 0).
* `cargo test --workspace` runs zero tests across the 53-crate workspace and exits 0 (`running 0 tests` — `test result: ok. 0 passed; 0 failed`), matching plan spec for stub-phase verification.
* `cargo doc --workspace --no-deps` generates 53 `index.html` files in 7m32s (exit 0); every stub crate has crate-level `//!` doc comment, `#![deny(missing_docs)]`, `#![forbid(unsafe_code)]`, and the `pub const VERSION` constant per Sprint 0.3 spec.
* Plan Section IV introduction gains a Meta-crate convention paragraph explaining why per-crate "Dependencies" lines list pulsar-framework as the public composition surface but inner stubs do not declare it as a Cargo dep (cycle through the meta-crate's re-exports). Inner crates depend directly on the smaller set of inner crates whose types they need (most commonly pulsar-kernel); the meta-crate aggregates the surface for downstream consumption. Standard meta-crate pattern from tokio, sqlx.
* Five v2.1 leftover references to "fifteen placeholder crates" / "fifteen crates" in Section V Phase 0 intro, Sprint 0.8, Sprint 4.6 exit criteria, Section IX.1, and Section IX.5 corrected to "fifty-three" — matching Section IV crate count and the workspace [members] list.

### Sprint 0.2 — Workspace Cargo manifests formal exit gate

* Three quality-gate commands all green: `cargo check --workspace --all-targets --all-features` exit 0, `cargo deny check` reports `advisories ok, bans ok, licenses ok, sources ok`, `cargo fmt --all -- --check` exit 0 with no warning noise.
* Dep version bumps eliminate four RUSTSEC IDs at the root: async-nats 0.38 → 0.47, rdkafka 0.37 → 0.39, instant-acme 0.7 → 0.8.
* deny.toml `[advisories.ignore]` populated with nine documented entries for transitive-only RUSTSEC IDs (instant, number_prefix, paste, rsa Marvin Attack via openidconnect, rustls-pemfile, three rustls-webpki name-constraint vulns, trust-dns-proto). Each entry has explicit Sprint 0.4 dep-hygiene target.
* deny.toml `[bans]` native-tls now uses `wrappers = ["hyper-tls", "tokio-native-tls", "reqwest", "ldap3"]` to allow the four legitimate transitive paths while preserving the direct-use ban.
* rustfmt.toml split into stable + commented-nightly sections (10 nightly-only options moved to documentation comments; stable `cargo fmt --check` no longer emits warnings).
* New `docs/ops/deployment/toolchain.md` documenting every pinned tool (rustc 1.95.0, mold linker, sccache, deny.toml policy, full quality-gate sequence) — plan Section V Sprint 0.2 documentation deliverable.

### Sprint 0.1 — Meta-files alignment

* README.md aligned with v2.2 plan: 53-crate workspace layout, 23-framework compliance count, native Web Components admin SPA replaces Leptos WASM, Mermaid architecture refreshed with consolidated layers, explicit links to docs/plan.md and docs/adr/INDEX.md per Sprint 0.1 exit criterion.
* SECURITY.md crypto section now reflects in-scope FIPS 140-3 validation pathway (Sprint 4.7), HSM/PKCS#11 (Sprint 2C.3), confidential computing (Sprint 4.8), and PQC hybrid (Sprint 1.1 + 2.5) per plan Section 14.
* CONTRIBUTING.md scope list expanded to all 53 crate names; quality-gate command sequence corrected (`cargo mutants --minimum-test-timeout 60` was previously the invalid `--minimum-test-efficacy 95`); new naming-conventions section pointing at plan Section XVII catalogue.
* Three blocking v2.1 baseline bugs surfaced and fixed: `subtle 2.7` (does not exist on crates.io, max is 2.6.1) → `subtle 2.6`; `acme-client 0.4` (abandoned 2017, transitively pulls banned `native-tls 0.1`) → `instant-acme 0.7` (modern async ACME v2 on hyper + rustls); meta-crate dependency cycle (40 inner stubs incorrectly declared `pulsar-framework` as a dep, creating cycles via meta-crate re-exports) — pulsar-framework removed from all inner stubs, `pulsar-kernel` added to pulsar-queue + pulsar-cache + pulsar-storage for AEAD primitive access.
* `trust-dns-resolver 0.23` (unmaintained per RUSTSEC-2025-0017) migrated to `hickory-resolver 0.24` across workspace.dependencies, pulsar-cluster Cargo.toml, and Section IV 4.50 spec.
* deny.toml license allow-list extended with five OSI/FSF-approved permissive licenses encountered in the resolved dep graph: `Apache-2.0 WITH LLVM-exception`, `MIT-0`, `0BSD`, `BSL-1.0`, `CDLA-Permissive-2.0`.
* Cargo.lock generated and committed for reproducible builds (per plan Section 14.6 SLSA 4 requirement and the binary nature of pulsar-cli).
* `cargo deny check licenses` now passes green; `cargo metadata --no-deps` parses cleanly across the 53-crate workspace with zero cycles.

### Added

- Master plan `docs/plan.md` v2.2 — full strict-parity rewrite roadmap with 53 first-party crates, 11 phases, 9 TLA+ specifications, 23 compliance framework mappings, and 50 strategic decisions.
- Cargo workspace manifest at `Cargo.toml` declaring 53 crate members across twelve layers (meta + kernel + core foundation + security controls + application infrastructure + data protection + authz + identity + application extensions + API paradigms + AI surface + reactive + dev experience + orchestration + infrastructure adapters + CLI + test).
- Pinned Rust 1.95.0 stable + edition 2024 via `rust-toolchain.toml` with full component set (`rustfmt`, `clippy`, `rust-analyzer`, `rust-src`, `llvm-tools-preview`).
- 53 crate stub directories under `crates/` with metadata, README, src/lib.rs scaffolding, and minimal `[dependencies]` sections wired to `[workspace.dependencies]` for external deps and `path` for internal sibling crates.
- Cargo workspace dependency table covering ~70 external crates pinned per plan Section 13.4 (ring, subtle, zeroize, secrecy, hyper 1.9, tokio 1.52, rustls 0.23, sqlx 0.8, tantivy 0.25, wasmtime 44, etc.) plus AWS/Azure/GCP SDKs, ClickHouse client, k8s-openapi.
- `LICENSE` (Apache-2.0 canonical text from apache.org).
- `NOTICE` with copyright, EUIPO trademark disclaimer, and third-party attribution policy.
- `.cargo/config.toml` wiring `mold` linker on Linux x86_64 and aarch64, sccache rustc-wrapper opt-in, sparse crates.io protocol, force-frame-pointers across desktop targets, and Section VI quality-gate aliases (`cargo check-all`, `cargo clippy-all`, `cargo cov`, `cargo deny-check`, etc.).
- `rustfmt.toml` (max_width 100, imports_granularity Module, group_imports StdExternalCrate, comment_width 100, format_strings).
- `clippy.toml` (cognitive-complexity 15, msrv 1.95.0, missing-docs-in-crate-items, too-many-arguments 5, too-many-lines 100).
- `deny.toml` covering six target triples (Linux glibc + musl x86_64/aarch64, macOS x86_64/aarch64, Windows MSVC, wasm32) with license allow-list (Apache-2.0, MIT, BSD-2/3, ISC, MPL-2.0, Unicode-DFS-2016, Unicode-3.0, Zlib, CC0-1.0, OpenSSL with ring exception), advisory zero-tolerance, and explicit bans on `native-tls`, `time < 0.3`, `chrono < 0.4.35`.
- Five GitHub Actions workflows in `.github/workflows/` aligned with Section VII tooling stack:
  - `ci.yml` — fmt + clippy + check + nextest + doc-tests + llvm-cov + cargo-deny + cargo-audit + cargo-machete + rustdoc strict + ADR-index integrity + CHANGELOG entry enforcement on PRs.
  - `nightly.yml` — cargo-mutants on diff or full workspace, cargo-fuzz short runs (10 minutes per target), Miri on the kernel with `MIRIFLAGS="-Zmiri-strict-provenance"`, optional cargo-semver-checks.
  - `audit.yml` — daily cargo-audit + cargo-deny + OSV-Scanner + OpenSSF Scorecard with SARIF upload to GitHub Security tab.
  - `benchmark.yml` — criterion regression tracker with 105% fail-on-alert threshold, baseline stored in `docs/perf/baselines/develop`.
  - `publish.yml` — tag-triggered Trusted Publisher OIDC publish across the dependency-ordered 53-crate matrix, GitHub Release with CHANGELOG-extracted notes.
- GitHub issue templates (`bug_report`, `feature_request`, `config`) and PR template scaffolded under `.github/`.
- `services/admin/` skeleton — native HTML5 + ES2025 + Web Components SPA with primitives library (`signals.js`, `router.js`, `api-client.js`), design-system tokens (OKLCH palette + spacing scale + typography), JSDoc strict checking via `tsconfig.json` (`checkJs: true`, `noEmit: true`, `target: ES2025`), WebdriverIO 9+ test harness scaffold.
- `services/operator/` skeleton — Go + kubebuilder Kubernetes Operator with `PROJECT` manifest and `go.mod` initialised.

### Decisions

- Decision 2.49 — Funding and sponsorship model: GitHub Sponsors plus Open Collective baseline from Phase 0, optional dual-licence commercial path post-GA for regulated organisations requiring counterparty contracts. Apache-2.0 remains the only OSS licence.
- Decision 2.50 — Trademark and patent posture: EUIPO trademark filed in Phase 0 (Class 9 + Class 42), Madrid Protocol international extension at GA covering UK, US, CH, CA, AU, SG, JP. Apache-2.0 defensive patent grant only; explicit no-offensive-patents pledge published in `docs/patent-non-aggression.md` at GA.

### Changed (vs v2.1 plan, before reconciliation)

- Section IV crate specs renumbered 4.12 through 4.53 to match the post-consolidation workspace tree; ghost specs for retired crates (`pulsar-csrf`, `pulsar-sri`, `pulsar-incident`, `pulsar-ratelimit`, `pulsar-resilience`, `pulsar-websocket`, `pulsar-broadcasting`, `pulsar-workflow`, `pulsar-saga`, `pulsar-supervisor`, `pulsar-service-discovery`) removed; six off-core crates (`pulsar-tickets`, `pulsar-feedback`, `pulsar-booking`, `pulsar-devices`, `pulsar-releases`, `pulsar-importexport`) removed per Section 16.13.
- Four consolidated crate specs added: 4.11 `pulsar-guard` (csrf + sri + incident + ratelimit + resilience + ssrf sub-modules), 4.38 `pulsar-realtime` (websocket + sse + webtransport + broadcasting sub-modules), 4.47 `pulsar-orchestration` (workflow + saga sub-modules), 4.50 `pulsar-cluster` (supervisor + discovery sub-modules).
- Three new state-of-art crate specs added: 4.28 `pulsar-authz` (RBAC + ABAC + ReBAC Zanzibar per Section 16.1.2), 4.29 `pulsar-identity-standards` (W3C VC + DIDs + JWS + COSE per Section 16.1.3 and 16.2.1), 4.42 `pulsar-ai-agents` (agentic framework with Computer Use adapter per Section 16.8.1).
- Section V Phase 1.5 collapsed five sub-sprints into a single Sprint 1.5 `pulsar-guard` covering all six sub-modules.
- Section V Phase 3B collapsed Sprint 3B.2 websocket + 3B.3 broadcasting + new webtransport into Sprint 3B.2 `pulsar-realtime`; renumbered remaining 3B sprints (graphql 3B.4 → 3B.3, grpc 3B.5 → 3B.4, mcp-server 3B.6 → 3B.5).
- Section V Phase 3C added Sprint 3C.4 `pulsar-ai-agents`.
- Section V Phase 3E collapsed workflow + saga into Sprint 3E.1 `pulsar-orchestration`, supervisor + service-discovery into Sprint 3E.4 `pulsar-cluster`; removed sprints for the six retired crates; renumbered remaining 3E sprints (cloud 3E.9 → 3E.2, edge 3E.10 → 3E.3, deploy 3E.13 → 3E.5).
- Section XVI extended with eight new sub-items: 16.1.5 eIDAS 2 EUDI Wallet, 16.1.6 FAPI 2.0 Open Banking, 16.1.7 PSD2 Strong Customer Authentication, 16.8.6 NIST AI RMF, 16.8.7 OWASP LLM Top 10, 16.10.6 cross-platform binary distribution + packaging, 16.12.8 OpenSSF Scorecard ≥ 9.0 + Best Practices Badge gold tier, 16.12.9 CIS Benchmarks + STIG (DISA).
- Section XII metrics row "TLA+ specifications" updated to 9 specs reflecting the new SAML spec and the consolidated orchestration spec (workflow + saga in one TLA+ module). Compliance row updated from "16 nominal (18 enumerated)" to "23 (22 mandatory + MiCA opt-in)". Crate-count row updated from "≥ 50" to "exactly 53".
- Section XV parity matrix sprint references realigned to post-consolidation numbering across `src/` modules table, extensions table, ADR carry-over, and VOID DRIFT gaps. Five PHP extensions (booking, feedback, releases, tickets, devices) re-marked `S` (superseded as downstream applications or absorbed into existing crates).
- Cargo.toml versions aligned with plan Section 13.4: hyper 1.7 → 1.9, tokio 1.47 → 1.52, plan subtle 2.5 → 2.7, plan Section IV 4.4 icu 2.0 → 2.2.
- Cargo.toml `[workspace.dependencies]` extended with clickhouse 0.13, aws-sdk-s3 1.56, azure_core 0.21, google-cloud-storage 0.22, k8s-openapi 0.24 (previously absent from workspace deps despite being referenced by `pulsar-observability`, `pulsar-cloud`, and `pulsar-cluster` specs).
- Cargo.toml `[workspace.dependencies]` `rdkafka` entry: dropped invalid `optional = true` flag (Cargo refuses optional flags on workspace.dependencies); per-crate `optional = true` declarations remain available where consumers feature-gate Kafka support (currently `pulsar-realtime` behind feature `kafka`).

### Notes

The Rust implementation replaces the former PHP implementation of Pulsar Framework. The final PHP release is tagged `v0.99.0-php-final` for historical reference. The v2.2 reconciliation prepares the workspace for Sprint 0.1 (meta-files), Sprint 0.2 (workspace Cargo manifests), Sprint 0.3 (53 crate stubs), Sprint 0.4 (CI workflows), Sprint 0.5 (initial ADRs), Sprint 0.6 (architecture diagrams), Sprint 0.7 (plan committed), Sprint 0.8 (foundation tag `v0.0.1-alpha.0` + namespace-reservation alpha publish to crates.io).
