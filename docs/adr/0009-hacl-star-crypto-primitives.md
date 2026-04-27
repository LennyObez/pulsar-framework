# ADR-0009: HACL\* via FFI for cryptographic primitives in `pulsar-kernel`

* **Status:** Accepted
* **Date:** 2026-04-27
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.53 (Kernel cryptographic primitives: HACL\* via FFI), Decision 2.20 (Formal verification: Creusot + TLA+ + HACL\* + SPARK 2014), Decision 2.14 (Crypto primitives: ring + subtle + zeroize + secrecy — superseded by 2.53)
* **Sprint:** Sprint 0.9-bis (v2.3 reconciliation closure); implementation Sprint 1.1 (kernel crypto)
* **Supersedes:** none (extends Decision 2.14)

## Context

Pulsar Framework targets banking + healthcare + government + legal verticals where cryptographic-defect blast-radius is denominated in regulatory fines, licensure loss, and criminal exposure. The v2.2 baseline crypto stack — `ring` 0.17 + `subtle` + `zeroize` + `secrecy` + `argon2` — is rigorously audited (NCC Group + Trail of Bits audits of `ring` published 2018 + 2020 + 2024) but **not formally verified**. Audit testifies that defect classes the auditors examined were absent at the audit date; verification proves defect classes are absent for all admissible inputs by construction.

For the regulated-domain target, the gap between "audited" and "formally verified" is the gap downstream procurement evaluates. Public-sector procurement in the EU (per CRA Annex II + NIS2 + PSD2 RTS) and in the US (per FedRAMP + NIST SP 800-53) is increasingly explicit about formally-verified cryptographic primitives as a maturity signal even where it is not yet a hard requirement.

In 2026 only one widely-deployed cryptographic library ships machine-verified proofs of correctness, memory safety, and secret independence (constant-time): **HACL\*** — written in F\* (a verification-oriented functional language) and extracted to optimised constant-time C. Production users include Mozilla Firefox NSS (TLS), Linux kernel WireGuard (Curve25519), Tezos blockchain, Microsoft Azure VPN, and the Zcash Foundation. The HACL\* C distribution + Rust FFI bindings is the only path that closes the verification gap without requiring Pulsar to invent + maintain a verified cryptographic library from scratch.

## Decision

The **cryptographic primitives in `pulsar-kernel` are sourced from HACL\*** via a `*-sys`-style FFI binding crate `pulsar-crypto-hacl-bindings` introduced in v2.3 per Decision 2.51. The Rust-side `pulsar-kernel::crypto` module re-exports the primitive API, layered with sealed-trait safety wrappers + Creusot contracts on the lifecycle invariants + capability-token gating on the public surface.

Primitives sourced from HACL\* (16 total, per Section XII success metric):

| Family | Primitive | NIST FIPS | HACL\* coverage |
|--------|-----------|-----------|------------------|
| AEAD | AES-128-GCM, AES-256-GCM | FIPS 197 + SP 800-38D | yes |
| AEAD | ChaCha20-Poly1305 | RFC 8439 | yes |
| Key agreement | Curve25519 (X25519) | RFC 7748 | yes |
| Key agreement | P-256 (NIST) | FIPS 186-5 | yes |
| Signature | Ed25519 | RFC 8032 | yes |
| Signature | ML-DSA-65, ML-DSA-87 | FIPS 204 (Aug 2024) | yes |
| KEM | ML-KEM-768, ML-KEM-1024 | FIPS 203 (Aug 2024) | yes |
| Hash | SHA-2-256/384/512 | FIPS 180-4 | yes |
| Hash | SHA-3-256/384/512 | FIPS 202 | yes |
| Hash | BLAKE2b, BLAKE2s | RFC 7693 | yes |
| KDF | HKDF (over SHA-2 family) | RFC 5869 | yes |
| MAC | HMAC | FIPS 198-1 | yes |

Primitives kept on `ring` + `subtle` + `argon2` + auxiliary crates:

* **Argon2id** — HACL\* does not yet ship Argon2 as of 2026Q1; `argon2` crate (audited, the canonical Rust implementation) provides the KDF for password hashing.
* **`subtle::ConstantTimeEq`** — HACL\* internals already use `subtle`; explicit re-export for downstream code that needs constant-time byte comparison primitives directly.
* **Legacy compatibility AEADs** for migration — AES-256-CBC + HMAC-SHA256 composite preserved at v0.9.x for migration tooling reading PHP-era stored material; deprecated at 1.0.

## Consequences

### Positive

* Cryptographic surface gains formal verification — strictly stronger than `ring`'s audited posture. Defects in the verified primitives (correctness, memory safety, constant-time / secret independence) are excluded for all admissible inputs by construction.
* Production deployment evidence is strong — Mozilla NSS + Linux WireGuard + Tezos + Azure VPN + Zcash all ship HACL\* in production. Pulsar joins an established consumer set.
* Aligns with Decision 2.20 formal-verification scope (microkernel layer); HACL\* extends that scope to the cryptographic primitives layer.
* PQC primitives (ML-KEM-768/1024, ML-DSA-65/87) are HACL\*-verified — no separate dependency for the post-quantum migration path per Decision 2.58.
* FedRAMP / NIST SP 800-53 / EU CRA procurement evaluations strengthen significantly — the formal-verification claim is auditable against published HACL\* proofs.

### Negative

* Build pipeline gains a C compiler dependency (HACL\* extracts to C). Mitigated by the `cc` crate which handles cross-platform C compilation via `build.rs`.
* SLSA Level 4 reproducibility requires vendoring the HACL\* C distribution under `pulsar-crypto-hacl-bindings/hacl-c/` with a pinned tag, which adds ~50 MB to the repository. Mitigated by the `*-sys` pattern's standard handling.
* Bindgen-generated FFI surface is unsafe Rust; the safe wrappers in `pulsar-kernel::crypto` add an audit boundary that requires careful Creusot contracts.
* HACL\* receives roughly biannual upstream releases; Pulsar must track these for security fixes + new primitives.
* `argon2` (KDF) remains outside the formal-verification boundary until HACL\* upstream adds it (no published timeline as of 2026Q1).

### Neutral

* `ring` is removed from the kernel surface but remains in the workspace for `subtle` interop. Downstream code accessing `ring` directly must migrate to the `pulsar-kernel::crypto` re-exports.
* Rust-side error mapping converts HACL\* C status codes to typed Rust errors via `pulsar-crypto-hacl-bindings::error::Error`.

## Alternatives considered

* **Keep ring + subtle as v2.2 (no HACL\*)**.
  Rejected: leaves the formal-verification gap on the most regulator-evaluated layer of the framework. The marginal cost of HACL\* adoption (one C compiler + bindgen) is small relative to the marginal gain (formal verification on the regulator-evaluated surface).
* **Adopt liboqs for PQC primitives only, keep ring for classical primitives**.
  Rejected: HACL\* now ships ML-KEM (FIPS 203) + ML-DSA (FIPS 204) finalised August 2024 with formal proofs. liboqs (the Open Quantum Safe project) is C with optional Rust bindings but not formally verified — strictly weaker than HACL\* on the same primitive set.
* **Write Pulsar's own crypto in pure Rust with Creusot contracts**.
  Rejected: years of effort, and even with Creusot would lack HACL\*'s production deployment evidence (Mozilla NSS / Linux / Tezos / Azure). Creusot contracts on cryptographic primitives are research-tier in 2026; HACL\* is industry-tier.
* **Use BoringSSL or libsodium**.
  Rejected: BoringSSL is C without formal proofs; libsodium is C audited but not formally verified. Both strictly weaker than HACL\* on the same primitive coverage.
* **Adopt RustCrypto suite (RustCrypto/aes-gcm, RustCrypto/chacha20poly1305, etc.)**.
  Rejected: RustCrypto is excellent but pure-Rust without formal proofs. Kept as fallback dependency for Argon2 (where HACL\* has no coverage).
* **Adopt `pulsar-spark-crypto/` SPARK 2014 implementation of crypto primitives**.
  Rejected: no published SPARK implementation of the AEAD + KEM + signature surface comparable to HACL\* exists. Building one would be years of effort and would lack production-deployment evidence.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.53 + 2.14 + 2.20 + 2.58, Section IV Workspace Layout (76 crates including `pulsar-crypto-hacl-bindings`), Section XII success metrics (16 HACL\* primitives row).
* Risk register entries: R-001 (Creusot prover coverage gap — HACL\* mitigates the crypto subset).
* Related ADRs: ADR-0003 (microkernel formal verification), ADR-0010 (SPARK invariants — sibling decision in v2.3), ADR-0012 (hybrid PQC from Sprint 1.1).
* External:
  * HACL\* upstream: https://github.com/hacl-star/hacl-star
  * Mozilla NSS HACL\* integration: https://hg.mozilla.org/projects/nss/file/tip/lib/freebl/verified
  * Linux WireGuard Curve25519 (HACL\*-derived): linux kernel `lib/crypto/curve25519-hacl64.c`
  * Polubelova, M. et al. "HACLxN: Verified Generic SIMD Crypto." CCS '20.
  * Bhargavan, K. et al. "Formal Verification of Smart Contracts." (HACL\* uses similar F\* techniques.)
  * F\* language: https://fstar-lang.org

## Compliance mapping

The HACL\* adoption is the single largest 2026 leap forward in the cryptographic-attestation surface that regulated-domain procurement evaluates. Every clause below is satisfied at a strictly higher tier than `ring`-only would deliver:

* **NIST FIPS 140-3 Level 2** (Decision 2.56 target — Sprint 4.7) — formal-verification artefacts (HACL\* proofs) strengthen the CMVP submission. The Cryptographic Module Validation Program explicitly considers formal-verification claims as supporting evidence.
* **NIST FIPS 203 (ML-KEM) + FIPS 204 (ML-DSA) + FIPS 205 (SLH-DSA)** — HACL\* is a NIST-finalist-tracking implementation. Pulsar's PQC posture per Decision 2.58 is grounded in HACL\*-verified primitives.
* **Common Criteria EAL 6+ / EAL 7** (Decision 2.52 target — Section 16.12.9) — formal proof of cryptographic primitives is required for EAL 6 (semi-formally verified design and tested) + mandated for EAL 7 (formally verified design and tested). HACL\* provides this for the primitives layer.
* **EU CRA (Regulation (EU) 2024/2847) Annex I § 1(d)** (designed, developed and produced to limit attack surfaces) — formally-verified primitives are the strongest available "limit attack surfaces" mechanism for cryptographic surface.
* **DORA Art. 9(2)** (preventive measures including authentication of users) — cryptographic primitives are the foundation for authentication; HACL\* verification means defects in the foundation are excluded by construction.
* **NIST SP 800-53 Rev. 5 SC-13** (cryptographic protection) — formally-verified cryptographic primitives strengthen SC-13 above the audited-only tier.
* **PCI-DSS 4.0 Req. 4.2.1** (strong cryptography defined and used) — HACL\* primitives meet "strong cryptography" with formal-verification backing exceeding the requirement.
* **eIDAS 2 (Regulation (EU) 910/2014 amended 2024)** — qualified electronic signature schemes require strong cryptographic primitives; HACL\*'s Ed25519 + ML-DSA verification supports qualified-signature-scheme integration in `pulsar-identity-standards`.
