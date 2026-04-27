# ADR-0013: Audit chain primitive — Ed25519 + Merkle tree + RFC 6962 transparency log

* **Status:** Accepted
* **Date:** 2026-04-27
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.55 (Audit chain primitive: Ed25519 + Merkle + RFC 6962 transparency log), Decision 2.20 (Formal verification scope — `spec/audit.tla` + SPARK Audit_Chain_Append_Only invariant), Decision 2.54 (SPARK invariants module covers append-only enforcement), Decision 2.31 (Strict parity with PHP — superseded for the audit chain primitive specifically)
* **Sprint:** Sprint 0.9-bis (v2.3 reconciliation closure); implementation Sprint 1.2 (audit + capability kernel sprint)
* **Supersedes:** Section 16.5 v2.2 audit-chain rationale ("HMAC-SHA256, strict carry-over from PHP `1.0.0-rc.11`")

## Context

The audit chain is the regulator-evaluated tamper-evident record of every consequential operation in a Pulsar deployment: authentication events, authorisation decisions, data accesses, configuration changes, security-control trips. For the regulated-domain target (banking + healthcare + government + legal), audit-chain integrity is denominated in regulatory exposure:

* **GDPR** Art. 30 (records of processing) + Art. 32 (security of processing — including the ability to ensure ongoing confidentiality, integrity, availability and resilience of processing systems).
* **HIPAA** 45 CFR § 164.312(b) (audit controls — implement hardware, software, and/or procedural mechanisms that record and examine activity in information systems).
* **PCI-DSS 4.0** Req. 10.5 (audit log integrity — protect audit trails from unauthorised modifications).
* **DORA** Art. 9(2) (preventive measures — including authentication, audit, and logging).
* **SOC 2** CC7.2 (audit logging — the entity monitors system components and the operation of those components).

The PHP-era Pulsar `1.0.0-rc.11` used **HMAC-SHA256** for the audit chain: each entry is HMAC-tagged with a server-side secret, and the chain links via the previous entry's HMAC. The v2.2 reconciliation carried this primitive over for parity (Decision 2.31).

The HMAC-SHA256 primitive has two structural limitations for the regulated-domain target:

1. **Verification requires the secret.** Anyone holding the HMAC key can both produce and verify entries. There is no way for an external regulator to verify the chain's integrity without being granted the secret — which itself is a key-management risk.

2. **Non-repudiation is absent.** A holder of the HMAC key can forge entries indistinguishable from authentic ones. There is no cryptographic basis for "this entry was produced by the audit subsystem and not by an attacker who compromised the HMAC key".

Both problems are well-understood in the cryptographic-engineering literature; the canonical solution is **public-key signatures + Merkle-tree aggregation + transparency-log publication**, as deployed by:

* **Sigstore Rekor** (transparency log for code-signing attestations — operational since 2021, used by every major Linux distro + cloud-native ecosystem).
* **Google Trillian** (general-purpose append-only log infrastructure, powers Certificate Transparency).
* **Certificate Transparency** (RFC 6962, RFC 9162 — operational since 2013, mandated for every public TLS certificate).

The construction:

* Each entry is canonicalised (deterministic field-ordered serialisation) + signed with an Ed25519 audit-key.
* Signed entries accumulate into a Merkle tree per RFC 6962 § 2.1 (left-balanced binary, leaf-prefix `0x00`, internal-prefix `0x01`).
* The current Merkle root is signed Ed25519 with an audit-root-key + published periodically (daily or on demand) to a public transparency log.

Verifiers (downstream consumers, auditors, regulators) can independently prove:

* **Authenticity per entry** — Ed25519 signature on the entry verifies under the published audit-key.
* **Inclusion per entry** — Merkle inclusion proof against the published root demonstrates the entry is in the chain.
* **Tamper-evidence at the chain level** — the published root is signed Ed25519 + recorded in the transparency log. Modifying any entry produces a different root; the transparency log retains the prior root, exposing the tampering.

None of these checks require access to the audit subsystem's private key. The verifier only needs the **public** audit-key + the transparency-log endpoint.

## Decision

The audit chain primitive **changes** from HMAC-SHA256 to **Ed25519 + Merkle + RFC 6962 transparency log**:

**Per-entry mechanism:**

```
canonical_entry = canonicalise(entry)
signature       = Ed25519_sign(audit_key, canonical_entry)
leaf            = SHA-256(0x00 || canonical_entry || signature)
```

**Merkle tree aggregation** (RFC 6962 § 2.1):

```
internal_node(L, R)  = SHA-256(0x01 || L || R)
root                  = recursive(left_subtree, right_subtree)  -- left-balanced binary
```

**Periodic root publication:**

```
signed_root = Ed25519_sign(audit_root_key, root || timestamp)
publish(signed_root)  -- to logs.pulsar-framework.com (operated by maintainer)
                      -- and (optionally) to Sigstore Rekor for federated trust
```

**Verification by external auditor:**

```
1. Fetch signed_root from logs.pulsar-framework.com (or Rekor mirror)
2. Verify Ed25519_verify(audit_root_key, signed_root, root || timestamp)
3. For each entry to verify:
   a. Recompute canonical_entry = canonicalise(entry)
   b. Verify Ed25519_verify(audit_key, signature, canonical_entry)
   c. Recompute leaf = SHA-256(0x00 || canonical_entry || signature)
   d. Verify Merkle inclusion proof against root
```

**Cryptographic primitives** sourced from HACL\* via `pulsar-crypto-hacl-bindings` per ADR-0009: SHA-256 + Ed25519. SHA-256 + Ed25519 are both formally verified in HACL\*; the audit-chain construction inherits formal-verification properties at the primitive layer.

**Append-only enforcement** is formally proven in SPARK 2014 via the `Audit_Chain_Append_Only::Verify_Append` function per ADR-0010. The SPARK postcondition guarantees uniqueness: for any `(Prev_Root, Entry, New_Root)` triple, there is at most one `New_Root` value that the verifier accepts. No two distinct `New_Root` values can extend the same `Prev_Root` with the same `Entry`.

**Transparency log infrastructure:**

* Primary: `logs.pulsar-framework.com` (provisioned at Sprint 1.2) — Pulsar Foundation-operated, single-instance initially, multi-region after GA.
* Federated mirror: Sigstore Rekor (compatibility mirror; downstream consumers who prefer the Sigstore trust root can verify there).
* Self-hosted option: `pulsar-deploy` ships a Trillian-compatible local-log container for air-gapped deployments where neither `logs.pulsar-framework.com` nor Rekor is reachable.

## Consequences

### Positive

* **Public verifiability.** External regulators and auditors verify the chain without access to the audit subsystem's private key. The verification step requires only the public audit-key + the transparency-log endpoint.
* **Non-repudiation.** Ed25519 signatures provide cryptographic non-repudiation: the audit subsystem cannot deny producing an entry it signed; the audit-key holder cannot forge entries indistinguishable from authentic ones (because forgery requires the same private key, which is stored in `pulsar-kernel`'s sealed-secret storage with capability gating).
* **Inclusion proof per entry.** Auditors can request a single entry + inclusion proof rather than the entire chain — efficient for large audit corpora.
* **Tamper-evidence at the chain level.** Modifying any entry produces a different Merkle root; the transparency log retains the prior root, exposing the tampering trivially.
* **Production deployment evidence.** The construction is the same as Sigstore Rekor (operating since 2021) + Certificate Transparency (operating since 2013) + Google Trillian (powers CT). The cryptographic engineering is well-understood + proven at scale.
* **HACL\*-verified primitives** (per ADR-0009) provide formal verification at the cryptographic-primitive layer.
* **SPARK-proven append-only invariant** (per ADR-0010) provides formal verification at the chain-construction layer.

### Negative

* **PHP parity is broken** for the audit chain primitive specifically. Migration tooling in `pulsar-cli` (Sprint 4.6) reads PHP-era HMAC chains + replays them into the v0.x Ed25519+Merkle chain.
* **Operational footprint grows.** The Pulsar Foundation operates `logs.pulsar-framework.com` (small but non-zero recurring cost; covered by the funding model per Decision 2.49).
* **Verification client surface.** Downstream auditors need a verifier — Pulsar ships `pulsar-cli verify-audit` (Sprint 4.6) + a Rekor-compatible REST API on `logs.pulsar-framework.com`.
* **Network dependency on the transparency log** (or Rekor mirror, or self-hosted Trillian). For air-gapped deployments the self-hosted option is mandatory.

### Neutral

* HMAC-SHA256 chain readers in legacy PHP downstream applications need migration. Pulsar provides the migration tool but the operational task is downstream's.
* Append performance: Ed25519 sign + SHA-256 hash + Merkle update per entry. On HACL\*-optimised paths, ≈ 50 µs per entry on x86-64. Negligible for HTTP-request-rate audit-event volumes.
* Merkle root publication is asynchronous + batched (default: every 5 minutes); does not affect per-entry latency.

## Alternatives considered

* **Keep HMAC-SHA256 (v2.2 baseline + PHP parity)**.
  Rejected per the two structural limitations (no public verification, no non-repudiation). The regulated-domain target requires both.
* **HMAC-SHA256 + periodic Ed25519 batch signature** (HMAC chain + Ed25519 sign on every page of N entries).
  Considered. Provides non-repudiation at batch granularity but loses inclusion-proof per entry. Verifying a single entry still requires the HMAC key. The marginal cost vs full Ed25519+Merkle is small; full construction wins.
* **BLS aggregate signature instead of Ed25519** (signatures aggregable across the chain).
  Rejected. BLS adds implementation complexity (pairing-friendly curves, more delicate side-channel discipline) without solving a problem Ed25519 + Merkle does not already solve. BLS aggregate signatures are appealing for blockchain consensus where signature size dominates; the Pulsar audit chain is not signature-size-bound.
* **ECDSA P-256 instead of Ed25519**.
  Rejected. Ed25519 is faster, has smaller signatures (64 vs 71-72 bytes), and avoids the implementation pitfalls of nonce-reuse-based ECDSA failures (Sony PlayStation 3 firmware-key 2010, Bitcoin transaction-hijacking 2013, etc.). Both are HACL\*-verified per ADR-0009; Ed25519 wins on engineering grounds.
* **Use Sigstore Rekor as the only log** (no first-party log).
  Rejected. Rekor is an excellent compatibility mirror but Pulsar should also operate its own first-party log for regulatory environments that require self-hosted evidence chains under jurisdiction-bound counterparty terms. Federated mirroring to Rekor is the best of both.
* **Stateful hash-based signatures (XMSS / LMS) instead of Ed25519**.
  Rejected. XMSS / LMS are post-quantum-secure but stateful (key state must be preserved across reboots — operational footgun). The PQC migration path per ADR-0012 + Decision 2.58 already covers Ed25519 → Ed25519 + ML-DSA-65 hybrid, which addresses post-quantum without statefulness.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.55 + 2.20 + 2.31, Section 16.5 (audit-chain rationale — superseded by this ADR), Section XII success metrics (audit chain primitive row).
* Risk register entries: R-002 (audit-chain integrity).
* Related ADRs: ADR-0003 (microkernel formal verification — `spec/audit.tla` covers the chain protocol), ADR-0009 (HACL\* crypto — provides Ed25519 + SHA-256), ADR-0010 (SPARK invariants — formally proves append-only enforcement), ADR-0012 (PQC migration path covers the audit-chain Ed25519 → ML-DSA-65 hybrid).
* External:
  * RFC 6962 (Certificate Transparency Merkle tree): https://datatracker.ietf.org/doc/html/rfc6962
  * RFC 9162 (Certificate Transparency Version 2.0): https://datatracker.ietf.org/doc/html/rfc9162
  * Sigstore Rekor: https://docs.sigstore.dev/logging/overview/
  * Google Trillian: https://github.com/google/trillian
  * Certificate Transparency: https://certificate.transparency.dev/

## Compliance mapping

* **GDPR Art. 30** (records of processing) — public verifiability + non-repudiation strengthen the integrity of records of processing beyond what HMAC-SHA256 provides.
* **GDPR Art. 32** (security of processing — integrity) — Ed25519 + Merkle + RFC 6962 transparency log is the strongest available primitive for "ensure ongoing integrity".
* **HIPAA 45 CFR § 164.312(b)** (audit controls) — formally-verified append-only enforcement (per ADR-0010 SPARK module) exceeds HIPAA's audit-control requirement at a tier the regulator recognises as evidence.
* **PCI-DSS 4.0 Req. 10.5** (audit log integrity) — Merkle + transparency log provides cryptographic integrity proof that PCI-DSS auditors recognise.
* **PCI-DSS 4.0 Req. 10.6** (review audit logs) — public verifiability lets the QSA review the chain integrity without access to the audit-key.
* **DORA Art. 9(2)(b)** (preventive measures — including authentication and access controls) — non-repudiation supports incident root-cause analysis under DORA Art. 17.
* **DORA Art. 28** (third-party risk management — auditing) — third-party auditors verify the chain independently without privileged access.
* **NIS2 Art. 21(2)(h)** (use of cryptography) — Ed25519 + SHA-256 are NIST-recommended primitives; Pulsar's audit-chain primitive complies.
* **SOC 2 CC7.2** (audit logging — monitoring of system components) — public verifiability + tamper-evidence are the controls SOC 2 evaluates.
* **eIDAS 2** (Regulation (EU) 910/2014 amended 2024) — qualified time-stamps + qualified signatures map cleanly to the Ed25519 + Merkle + transparency log construction.
