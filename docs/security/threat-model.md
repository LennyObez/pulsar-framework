# Threat model — Pulsar Framework

> **Status (2026-04-25, Phase 0 baseline).** Skeleton landed during Sprint
> 0.9 hardening; per-layer STRIDE elaboration arrives with the corresponding
> Phase 1+ sprint that delivers the layer (the same sprint adding the
> code adds the threats, mitigations, and verifications). Every newly
> threat-modelled element MUST be linked back to (a) the originating ADR,
> (b) the implementing crate, (c) the validating test or specification.

## Scope

This threat model covers the **Pulsar Framework codebase** — the 53
first-party crates, the `services/admin` SPA, and the `services/operator`
Kubernetes operator. Out of scope: applications built **with** Pulsar
(those should publish their own threat models referencing this one) and
the host operating system / hypervisor / cloud control plane (Pulsar
relies on the trusted-computing base; mitigation here is layered defence
plus minimum-privilege deployment guides).

Companion documents:

- `docs/security/policy.md` — disclosure procedure, contact channels, embargo policy.
- `docs/security/sbom.md` — SBOM generation and consumption.
- `docs/security/cvss.md` — severity calculation methodology (CVSS 4.0).
- `docs/adr/0007-supply-chain-baseline.md` — supply-chain controls.
- `SECURITY.md` — top-level entry point for reporters.

## Methodology

Threats are catalogued using the **STRIDE** taxonomy (Spoofing,
Tampering, Repudiation, Information disclosure, Denial of service,
Elevation of privilege) per architectural layer. Each entry records:

- **Threat ID** (`PSF-<layer>-<n>`, e.g. `PSF-KERNEL-3`).
- **STRIDE class.**
- **Asset** affected.
- **Adversary capability** assumed (network attacker / coresident tenant / privileged insider / supply-chain compromise).
- **Likelihood** (Low / Medium / High) and **impact** (Low / Medium / High / Critical) per CVSS 4.0 base metrics.
- **Mitigation** — design-level control + crate-level enforcement.
- **Verification** — the test, fuzz target, or formal proof that demonstrates the mitigation holds.
- **Residual risk** — what is explicitly accepted, by whom, and under which exception.
- **Status** — `mitigated` / `partially-mitigated` / `accepted` / `pending`.

Pending threats appear with `pending` and a target sprint. A threat
model is not a wishlist — every `pending` entry has an owner and a
sprint commitment.

## Trust boundaries

The framework assumes **eight named trust boundaries**:

```
[ public internet ]──────► [ TLS terminator / WAF ] ──────► [ ingress (L7) ]
       │ T1                       │ T2                               │ T3
       ▼                          ▼                                  ▼
                              [ pulsar-http ]
                                    │ T4
                                    ▼
                              [ pulsar-engine + framework crates ]
                                    │ T5                                  │ T6
                                    ▼                                    ▼
                            [ pulsar-orm  / sqlx ]              [ pulsar-kernel sealed core ]
                                    │ T7                                  │ T8
                                    ▼                                    ▼
                            [ database / KMS / object store ]    [ HSM / TPM / Secure Enclave ]
```

| Boundary | From | To | Auth/integrity control |
|----------|------|-----|------------------------|
| T1 | Public internet | TLS terminator | TLS 1.3 + HSTS + ECH (when supported). |
| T2 | TLS terminator | Ingress | Mutual TLS optional; PROXY-protocol attestation. |
| T3 | Ingress | `pulsar-http` | Internal mesh mTLS + ALPN-pinned. |
| T4 | `pulsar-http` | Application crates | Capability tokens (no ambient authority). |
| T5 | Framework crates | `pulsar-orm` | SQL-builder typed surface; no string concatenation. |
| T6 | Framework crates | `pulsar-kernel` | Sealed traits + Creusot contracts. |
| T7 | `pulsar-orm` | DB / KMS / object store | TLS + IAM-bound credentials + per-row encryption (where required by `pulsar-dataprotection`). |
| T8 | `pulsar-kernel` | HSM / TPM / Secure Enclave | PKCS#11 / TPM 2.0 / KMIP — never raw key material in process memory. |

## Per-layer STRIDE catalogue (target shape)

The following sections define the structure each per-layer entry follows.
Every entry is filled in by the sprint that delivers the corresponding
layer; until then the section title is a placeholder pointer to the
target sprint.

### Layer 1 — Kernel (`pulsar-kernel`) — Sprint 1.x

Threats track Section 14.6 (SLSA L4) + Section 16.5.5 (formal verification scope).

| ID | STRIDE | Asset | Adversary | L | I | Status | Sprint |
|----|--------|-------|-----------|---|---|--------|--------|
| `PSF-KERNEL-1` | Information disclosure | AEAD key material | Co-resident process via /dev/mem | L | Critical | pending | 1.1 |
| `PSF-KERNEL-2` | Tampering | Capability token | Forging caller | L | Critical | pending | 1.2 |
| `PSF-KERNEL-3` | Elevation of privilege | Sealed-trait downgrade | Malicious extension | L | Critical | pending | 1.2 |
| `PSF-KERNEL-4` | Denial of service | RNG starvation | Resource exhaustion | M | Medium | pending | 1.1 |

Specifications: `spec/crypto.tla`, `spec/saga.tla`.

Verifications: Creusot contracts on `pulsar-kernel::crypto::aead`,
`cargo fuzz` AEAD fuzzers (10M iterations zero-crash), miri under
`nightly.yml`, two-builder reproducibility under `repro-build.yml`.

### Layer 2 — Security controls (`pulsar-guard`) — Sprint 2.x

CSRF, SRI, ratelimit, SSRF-guard, incident escalation. Threats track
Section 16.4 (security controls) + OWASP ASVS L3.

(Per-threat catalogue lands with Sprint 2.1.)

### Layer 3 — Core foundation — Sprints 3.x

`pulsar-http`, `pulsar-engine`, `pulsar-orm`, `pulsar-auth`,
`pulsar-audit`, `pulsar-compliance`, `pulsar-observability`,
`pulsar-search`. Threats track Section 16.5 + OWASP ASVS L3.

(Per-threat catalogue lands per crate sprint.)

### Layer 4 — Data protection + Authz + Identity — Sprints 4.x

Threats track Section 16.6 (RtbF, redaction, consent ledger) +
Section 16.7 (authorisation).

(Per-threat catalogue lands per crate sprint.)

### Layer 5 — Application infrastructure — Sprints 5.x

Threats track Section 16.8.

(Per-threat catalogue lands per crate sprint.)

### Layer 6 — Application extensions — Sprints 6.x

Threats track Section 16.9.

(Per-threat catalogue lands per crate sprint.)

### Layer 7 — API paradigms — Sprints 7.x

REST, GraphQL, gRPC, MCP, WebSocket. Threats track Section 16.10 +
OWASP API Top 10:2023.

(Per-threat catalogue lands per crate sprint.)

### Layer 8 — AI surface — Sprints 8.x

Threats track Section 16.11 + OWASP LLM Top 10:2023:

- Prompt injection (LLM01).
- Insecure output handling (LLM02).
- Training-data poisoning (LLM03) — relevant to retrieval store, not model training (Pulsar consumes models, never trains them).
- Model denial of service (LLM04) — adversarial token bombs.
- Supply-chain vulnerabilities (LLM05) — model provenance + signed weights.
- Sensitive information disclosure (LLM06) — PII leak via embedding inversion.
- Insecure plugin design (LLM07) — capability-bound tool surface in `pulsar-mcp-server`.
- Excessive agency (LLM08) — `pulsar-ai-agents` capability gating.
- Overreliance (LLM09) — UX guardrails (out of framework scope; documented in `docs/book/src/concepts/ai`).
- Model theft (LLM10) — operator concern, documented in `docs/security/threat-model-deployment.md`.

(Per-threat catalogue lands with Sprint 8.1.)

### Layer 9 — Reactive + dev experience — Sprints 9.x

Threats track Section 16.5.4 (live admin) + WCAG 2.2 AAA security
implications (CAPTCHA accessibility, etc.).

(Per-threat catalogue lands per crate sprint.)

### Layer 10 — Infrastructure adapters — Sprints 10.x

Threats track Section 16.10.6 (multi-region, edge, k8s).

(Per-threat catalogue lands per crate sprint.)

### `services/admin` SPA — Sprint A.x

Threats track Section 7.1 + OWASP ASVS L3 + WCAG 2.2 AAA.

| Threat class | Mitigation |
|--------------|------------|
| XSS via Web Component innerHTML | Trusted Types policy + Shadow DOM + lit-style html`` template tag enforced via lint (Sprint A.1). |
| CSRF on admin API | Double-submit cookie + Origin / Sec-Fetch-Site validation in `pulsar-guard`. |
| SPA bundle tampering | SRI hashes embedded by `pulsar-console-api`. |
| DOM clobbering | Shadow DOM isolation + no global writeable `document.<name>` exposure. |
| Open redirect | Allowlist via `pulsar-guard::redirect`. |

### `services/operator` (Kubernetes) — Sprint K.x

Threats track Decision 2.46 + Kubernetes security best practices.

| Threat class | Mitigation |
|--------------|------------|
| CRD escalation | RBAC: operator runs with namespace-scoped role; ClusterRole only for CRD list/watch. |
| Webhook MITM | Webhook server uses cert-manager-issued cert + `caBundle` rotation. |
| Secret exfiltration | `PulsarSecret` CRD references External Secrets Operator; never inlines material. |
| Compromised image | Cosign keyless verification at admission via Sigstore-policy-controller. |

## Cross-cutting threats

### Supply-chain (cross-layer)

Tracked by `cargo-deny`, `cargo-audit`, OSV-Scanner, OpenSSF Scorecard,
SLSA Level 4 reproducible builds, Cosign keyless signing, and Trusted
Publisher OIDC. Specific entries:

| ID | Threat | Mitigation |
|----|--------|------------|
| `PSF-SC-1` | Typosquatted dependency | `cargo-deny` `bans.deny` allowlist + manual review for every new dep. |
| `PSF-SC-2` | Compromised registry account | Trusted Publisher OIDC — no long-lived registry tokens. |
| `PSF-SC-3` | Build-server tampering | Two-builder repro-build verification on every PR. |
| `PSF-SC-4` | Yanked / unmaintained transitive | RustSec advisory check + 12-month-max-staleness policy. |
| `PSF-SC-5` | Dependency confusion | Workspace `Cargo.toml` pins exact versions; lockfile committed. |

### Cryptographic-agility

Tracked by ADR-0003 (PQC migration plan). Threats:

- Algorithm break (RSA / ECDSA → harvest-now-decrypt-later).
- Implementation flaw in `ring` requiring rapid swap.
- Side-channel leakage in stateful operations.

Mitigation: hybrid PQC (Kyber + ML-KEM) once Sprint 1.5 ships
`pulsar-pqc`; constant-time discipline in `pulsar-kernel`; explicit
`pulsar-kernel::crypto::Algo` versioning so on-disk material can be
re-encrypted at the next minor.

### Insider threat

Tracked by Decision 2.30 (GPG-signed commits) + CODEOWNERS + branch
protection + reproducible builds. The single-maintainer Phase 0 posture
is acknowledged as a residual risk; Section 16.13.1 documents the
mitigation (multi-sig PR approval activated when contributor count ≥ 3).

## Review cadence

The threat model is reviewed:

- On every sprint-exit that delivers a layer (incremental update by the
  sprint owner + one independent reviewer).
- At every MAJOR release boundary (full re-walk of the trust boundary
  diagram + STRIDE catalogue).
- Whenever a CVE is filed against a transitive dependency in a sensitive
  crate (e.g. `ring`, `rustls`).
- Whenever Section II decisions add a new architectural axis (e.g.
  Decision 2.50 added the EUIPO trademark, prompting `services/admin`
  branding-tampering threats).

## Acknowledgements

The methodology draws from:

- NIST SP 800-30 Rev. 1 — Risk assessment.
- ISO/IEC 27005:2022 — Information-security risk management.
- Microsoft Threat Modeling Tool STRIDE-LM extension.
- OWASP ASVS L3 + OWASP API Top 10:2023 + OWASP LLM Top 10:2023.
- The CHERI capability-based threat-model worksheet.
- The seL4 verification methodology paper (Klein et al., 2009).
