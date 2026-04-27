# Compliance framework matrix — Pulsar Framework

> **Status (2026-04-27, v2.3 reconciliation closure).** Pulsar Framework targets compliance against **31 regulatory frameworks** at GA per Decision 2.52 v2.3 lock-in: 30 mandatory + 1 opt-in (MiCA for crypto-asset service providers in EU). The v2.3 expansion adds 8 new frameworks to the v2.2 baseline of 22 mandatory + MiCA opt-in.
>
> Each framework section below records: applicable jurisdiction(s), the implementing crate(s), the technical control points (with file/function pointers landing as the implementation matures), the testing + audit evidence requirements, and the certification or attestation pathway where one is in scope.

## How to read this document

Pulsar's compliance posture is **encoded as code** rather than maintained as a separate compliance manifest that drifts from the implementation. The matrix below is the canonical mapping from regulatory clauses to:

- **Implementing crate(s)** — the workspace member(s) that provide the technical control.
- **Decision reference(s)** — Section II decisions in `docs/plan.md` and ADRs in `docs/adr/` that lock in the design.
- **Test + audit evidence** — the test harness, formal-verification spec, or audit-chain entry that demonstrates the control operates correctly.

For every clause Pulsar makes a claim against, there is a corresponding test or specification that mechanically demonstrates the claim. For every clause Pulsar does **not** address (because it falls outside framework scope), the entry calls that out explicitly so downstream applications can layer their own controls.

## The 31 frameworks at a glance

| # | Framework | Jurisdiction | Domain | Mandatory? | Status |
|---|-----------|--------------|--------|:----------:|--------|
| 1 | **GDPR** (Regulation (EU) 2016/679) | EU + EEA | General data protection | yes | v2.2 |
| 2 | **UK GDPR** (Data Protection Act 2018) | UK | General data protection | yes | v2.2 |
| 3 | **HIPAA** (45 CFR Parts 160, 162, 164) | US | Healthcare | yes | v2.2 |
| 4 | **PCI-DSS 4.0** | Global | Payment cards | yes | v2.2 |
| 5 | **PSD2** + RTS (Regulation (EU) 2018/389) | EU | Payments | yes | v2.2 |
| 6 | **DORA** (Regulation (EU) 2022/2554) | EU | Financial-sector ICT resilience | yes | v2.2 |
| 7 | **SOC 2** (AICPA Trust Service Criteria) | US (global use) | Service-org control attestation | yes | v2.2 |
| 8 | **ISO/IEC 27001:2022** | Global | Information security management | yes | v2.2 |
| 9 | **ISO/IEC 27017:2015** | Global | Cloud-services security controls | yes | v2.2 |
| 10 | **ISO/IEC 27018:2019** | Global | Cloud-services PII protection | yes | v2.2 |
| 11 | **ISO/IEC 27701:2019** | Global | Privacy information management | yes | v2.2 |
| 12 | **NIS2** (Directive (EU) 2022/2555) | EU | Network + information security | yes | v2.2 |
| 13 | **eIDAS 2** (Regulation (EU) 910/2014 amended 2024) | EU | Electronic identification + signatures | yes | v2.2 |
| 14 | **COPPA** (15 USC §§ 6501-6506) | US | Children's online privacy | yes | v2.2 |
| 15 | **FERPA** (20 USC § 1232g) | US | Educational records | yes | v2.2 |
| 16 | **CCPA / CPRA** (Cal. Civ. Code § 1798.100+) | US — California | Consumer privacy | yes | v2.2 |
| 17 | **LGPD** (Lei Geral de Proteção de Dados — Brazil) | BR | General data protection | yes | v2.2 |
| 18 | **APPI** (Act on Protection of Personal Information — Japan) | JP | General data protection | yes | v2.2 |
| 19 | **PIPL** (Personal Information Protection Law — China) | CN | General data protection | yes | v2.2 |
| 20 | **POPIA** (Protection of Personal Information Act — South Africa) | ZA | General data protection | yes | v2.2 |
| 21 | **NDB scheme** (Australian Privacy Act Notifiable Data Breaches) | AU | Breach notification | yes | v2.2 |
| 22 | **Mexican LFP** (Ley Federal de Protección de Datos Personales) | MX | General data protection | yes | v2.2 |
| 23 | **India DPDP Act 2023** (Digital Personal Data Protection) | IN | General data protection | yes | v2.2 |
| 24 | **NYDFS Part 500** (23 NYCRR 500) | US — New York | Financial services cybersecurity | yes | **v2.3 NEW** |
| 25 | **MAS TRM** (Monetary Authority of Singapore Technology Risk Management) | SG | Banking + insurance ICT risk | yes | **v2.3 NEW** |
| 26 | **APRA CPS 234** (Australian Prudential Regulation Authority Information Security) | AU | Banking + insurance + super | yes | **v2.3 NEW** |
| 27 | **OSFI B-13** (Canadian Office of the Superintendent of Financial Institutions Technology and Cyber Risk Management) | CA | Federal financial institutions | yes | **v2.3 NEW** |
| 28 | **RBI Cybersecurity Framework for Banks** (Reserve Bank of India 2016 + 2025 updates) | IN | Banking | yes | **v2.3 NEW** |
| 29 | **Quebec Law 25** (Act respecting the protection of personal information in the private sector — modernised) | CA — Quebec | General data protection (francophone Canada) | yes | **v2.3 NEW** |
| 30 | **Switzerland nFADP** (Federal Act on Data Protection in force since Sept 2023) / **nLPD** (Loi fédérale sur la protection des données) | CH | General data protection | yes | **v2.3 NEW** |
| 31 | **Common Criteria ISO/IEC 15408 — EAL 6+ / EAL 7** | Global | Product certification (formal verification target) | target | **v2.3 NEW** |
| **opt-in** | **MiCA** (Markets in Crypto-Assets Regulation (EU) 2023/1114) | EU | Crypto-asset service providers | opt-in | v2.2 |

## Per-framework mappings

The eight v2.3 frameworks added in this expansion each get a dedicated section below. The 22 v2.2 mandatory + MiCA opt-in are summarised at high level here; their detailed mappings live in `docs/compliance/frameworks/<name>.md` (per-framework dossiers landing at the implementing-sprint exit).

### 24. NYDFS Part 500 (23 NYCRR 500) — US New York financial services cybersecurity (v2.3 NEW)

**Scope.** Any "Covered Entity" — every individual or non-governmental entity operating under or required to operate under a license, registration, charter, certificate, permit, accreditation, or similar authorisation under the New York Banking Law, Insurance Law, or Financial Services Law. In practice: every bank, insurer, mortgage broker, funds-transfer firm, virtual-currency company, and money-transmitter doing business in NY State.

**Core requirements.**

| Section | Requirement | Pulsar implementing crate(s) | Decision / ADR |
|---------|-------------|------------------------------|----------------|
| § 500.2 | Maintain a cybersecurity program | `pulsar-compliance` + `pulsar-audit` | ADR-0011, Decision 2.45 |
| § 500.3 | Cybersecurity policy | `pulsar-compliance::policy` | ADR-0014 |
| § 500.4 | Chief Information Security Officer (CISO) | (organisational, not framework — Pulsar provides the audit-trail evidence the CISO reports against) | — |
| § 500.5 | Penetration testing + vulnerability assessments | CI integration via `audit.yml` (cargo-audit + OSV-Scanner + Scorecard) + Sprint 4.4 advanced fuzzing | ADR-0007 |
| § 500.6 | Audit trail (≥ 3-year retention; ≥ 5-year retention for high-risk events) | `pulsar-audit` with Ed25519 + Merkle + RFC 6962 transparency log | ADR-0013 |
| § 500.7 | Access privileges + capability gating | `pulsar-authz` + `pulsar-kernel::capability` | ADR-0010 |
| § 500.8 | Application security (SDLC) | `docs/security/threat-model.md` + `pulsar-guard` sub-module suite | ADR-0011 |
| § 500.9 | Risk assessment | `pulsar-compliance::risk` | Decision 2.52 |
| § 500.11 | Third-party service-provider security policy | `cargo-deny` + Trusted Publisher OIDC + SLSA L3+L4 + in-toto | ADR-0006 (namespace + Trusted Publisher), ADR-0007 (status checks incl. cargo-deny), Decision 2.57 (SLSA + in-toto) |
| § 500.12 | Multi-factor authentication | `pulsar-auth::mfa` (WebAuthn/Passkey/TOTP/SMS-as-fallback) | (Sprint 2.5 ADR) |
| § 500.13 | Limitations on data retention | `pulsar-dataprotection::retention` (RtbF two-phase commit + retention timer) | ADR-0010 spec/rtbf.tla |
| § 500.14 | Training + monitoring | (organisational — Pulsar produces the audit-chain evidence) | — |
| § 500.15 | Encryption of nonpublic information | `pulsar-kernel::crypto` (HACL\*-verified AEAD + KEM) + `pulsar-storage` SSE | ADR-0009, ADR-0012 |
| § 500.16 | Incident response plan | `pulsar-incident` (v2.3 dé-fusion crate) + audit-chain integration | ADR-0011 |
| § 500.17 | Notice to Superintendent (72-hour breach notification) | `pulsar-incident::notify` with NYDFS adapter | (Sprint 2A.4 ADR) |
| § 500.19 | Cybersecurity event reporting | `pulsar-compliance::reporting::nydfs` | (Sprint 2.6 ADR) |

**Per-framework dossier:** `docs/compliance/frameworks/nydfs-part-500.md` (lands Sprint 2.6).

### 25. MAS TRM — Singapore Monetary Authority Technology Risk Management (v2.3 NEW)

**Scope.** Every MAS-regulated entity in Singapore: banks, insurers, capital markets services, payment service providers, exchange operators. The MAS TRM Guidelines are mandatory by way of MAS Notice on Technology Risk Management; non-compliance triggers supervisory action under the Banking Act + Insurance Act + Securities and Futures Act.

**Core requirements.**

| Chapter | Requirement | Pulsar implementing crate(s) | Decision / ADR |
|---------|-------------|------------------------------|----------------|
| 3 | Technology risk governance | `pulsar-compliance::governance` | Decision 2.52 |
| 5 | IT project management + system development | ADR-0007 (branch model) + ADR-0014 (test discipline) + ADR-0011 (architecture) | — |
| 6 | IT service management | `pulsar-incident` + `pulsar-resilience` | ADR-0011 |
| 7 | System reliability + availability | `pulsar-resilience` + `pulsar-supervisor` + `pulsar-service-discovery` | ADR-0011 |
| 8 | Operational infrastructure security management | `pulsar-guard` sub-modules + `pulsar-kernel::capability` | ADR-0011, ADR-0010 |
| 9 | Data centre + cloud services | `pulsar-cloud` + driver crates per Decision 2.51 | ADR-0011 |
| 10 | Access control | `pulsar-authz` + `pulsar-auth` | (Sprint 2.5 ADR) |
| 11 | Cryptography | `pulsar-kernel::crypto` (HACL\*-verified) + ADR-0012 (PQC) | ADR-0009, ADR-0012 |
| 12 | Audit logging | `pulsar-audit` (Ed25519 + Merkle + RFC 6962) | ADR-0013 |
| 13 | Cybersecurity incident management | `pulsar-incident` + Sigstore Rekor mirror | ADR-0011, ADR-0013 |
| 14 | Cyber threat intelligence + information sharing | `pulsar-compliance::threat-intel` (STIX/TAXII outbound) | (Sprint 2.6 ADR) |

**Per-framework dossier:** `docs/compliance/frameworks/mas-trm.md` (lands Sprint 2.6).

### 26. APRA CPS 234 — Australian Prudential Regulation Authority Information Security (v2.3 NEW)

**Scope.** All APRA-regulated entities: authorised deposit-taking institutions (banks, credit unions, building societies), insurers (general, life, health), and registrable superannuation entities. Mandatory since 1 July 2019; revised 2024.

**Core requirements.**

| Para | Requirement | Pulsar implementing crate(s) | Decision / ADR |
|------|-------------|------------------------------|----------------|
| 13 | Information security capability | `pulsar-compliance::capability-evidence` | Decision 2.52 |
| 18 | Information assets — identify and classify | `pulsar-dataprotection::classification` | (Sprint 2C.2 ADR) |
| 22-23 | Implementation of controls — security testing programme | CI quality gates + `cargo mutants ≥ 99 %` (per ADR-0014) | ADR-0014 |
| 24-26 | Implementation of controls — incident management | `pulsar-incident` + `pulsar-audit` | ADR-0013 |
| 27-30 | Internal audit | `pulsar-audit` Merkle-root attestation + Sigstore Rekor independent verifier | ADR-0013 |
| 32-35 | APRA notification (no later than 72 hours of becoming aware) | `pulsar-incident::notify::apra-cps-234` | (Sprint 2A.4 ADR) |

**Per-framework dossier:** `docs/compliance/frameworks/apra-cps-234.md` (lands Sprint 2.6).

### 27. OSFI B-13 — Canadian Office of the Superintendent of Financial Institutions Technology and Cyber Risk Management (v2.3 NEW)

**Scope.** All federally regulated financial institutions (FRFIs) in Canada: banks, federally-incorporated trust + loan companies, federally-incorporated insurers, federally-incorporated cooperative credit associations. Effective 1 January 2024.

**Core requirements.**

| Section | Requirement | Pulsar implementing crate(s) | Decision / ADR |
|---------|-------------|------------------------------|----------------|
| Domain 1: Governance + risk management | Operational + cyber risk governance | `pulsar-compliance::governance` | Decision 2.52 |
| Domain 2: Technology operations + resilience | Patch + change + incident + IT operations | ADR-0007 (branch model) + `pulsar-resilience` + `pulsar-incident` | ADR-0011 |
| Domain 3: Cybersecurity | Identification + protection + detection + response + recovery (NIST CSF aligned) | `pulsar-guard` sub-modules + `pulsar-incident` + `pulsar-audit` | ADR-0011, ADR-0013 |

**Per-framework dossier:** `docs/compliance/frameworks/osfi-b-13.md` (lands Sprint 2.6).

### 28. RBI Cybersecurity Framework for Banks (v2.3 NEW)

**Scope.** All scheduled commercial banks in India. RBI circular DBS.CO.ITC.BC.No.5/31.01.015/2025-26 (latest 2025 update of the original 2016 framework). Cooperative banks have a parallel framework.

**Core requirements.**

| Annex | Requirement | Pulsar implementing crate(s) | Decision / ADR |
|-------|-------------|------------------------------|----------------|
| Part A | Cyber crisis management plan | `pulsar-incident::crisis` | ADR-0011 |
| Part B | Cybersecurity policy + organisational structure | `pulsar-compliance::governance` + `docs/security/threat-model.md` | Decision 2.52 |
| Annex 1 | Baseline cybersecurity + resilience requirements (51 controls) | Mapped per-control in `docs/compliance/frameworks/rbi-cyber.md` | ADR-0011 |
| Annex 2 | Setting up + operationalising of Cyber Security Operation Centre (C-SOC) | `pulsar-observability` + `pulsar-audit` + `pulsar-incident` | ADR-0013 |
| Annex 3 | Reporting of unusual cyber security incidents | `pulsar-incident::notify::rbi` | (Sprint 2A.4 ADR) |

**Per-framework dossier:** `docs/compliance/frameworks/rbi-cybersecurity-framework.md` (lands Sprint 2.6).

### 29. Quebec Law 25 — Quebec Act respecting the protection of personal information in the private sector (v2.3 NEW)

**Scope.** Every private-sector entity holding personal information about residents of Quebec, regardless of where the entity is located. Modernised 22 September 2021; phased entry into force completed 22 September 2024.

**Core requirements.**

| Section | Requirement | Pulsar implementing crate(s) | Decision / ADR |
|---------|-------------|------------------------------|----------------|
| 3.1 | Privacy officer designation (Chief Privacy Officer) | (organisational — Pulsar provides the audit-trail evidence) | — |
| 3.2 | Privacy impact assessment (PIA) | `pulsar-compliance::pia` | (Sprint 2.6 ADR) |
| 3.3 | Privacy policies | `pulsar-compliance::policy` | (Sprint 2.6 ADR) |
| 3.4 | Notice to commission (Commission d'accès à l'information) of confidentiality incident | `pulsar-incident::notify::quebec-cai` | (Sprint 2A.4 ADR) |
| 4 | Lawful basis for processing | `pulsar-consent` (purpose-bound consent ledger) | (Sprint 2C.1 ADR) |
| 5-8.1 | Right to access + correction + erasure (RtbF) | `pulsar-dataprotection::rtbf` (two-phase commit) | ADR-0010 spec/rtbf.tla |
| 28.1 | Data portability | `pulsar-dataprotection::portability` | (Sprint 2C.2 ADR) |
| 12.1 | Anonymisation requirements (under regulations) | `pulsar-dataprotection::anonymise` | (Sprint 2C.2 ADR) |

**Per-framework dossier:** `docs/compliance/frameworks/quebec-law-25.md` (lands Sprint 2.6).

### 30. Switzerland nFADP / nLPD — Federal Act on Data Protection (v2.3 NEW)

**Scope.** Every entity (Swiss or foreign) processing personal data of individuals in Switzerland or having effects in Switzerland. Replaced the 1992 act on 1 September 2023.

**Core requirements.**

| Article | Requirement | Pulsar implementing crate(s) | Decision / ADR |
|---------|-------------|------------------------------|----------------|
| Art. 6 | Principles (lawfulness, good faith, proportionality, recognisability, retention) | `pulsar-dataprotection` + `pulsar-consent` | (Sprint 2C ADR) |
| Art. 7 | Data protection by design and by default | `pulsar-dataprotection` + ADR-0011 (hexagonal ports for adapter swapping) | ADR-0002 |
| Art. 8 | Data security (technical + organisational measures) | `pulsar-kernel::crypto` (HACL\*) + `pulsar-guard` sub-modules + `pulsar-audit` (Ed25519+Merkle) | ADR-0009, ADR-0011, ADR-0013 |
| Art. 24 | Notification of data security breach to FDPIC | `pulsar-incident::notify::fdpic` | (Sprint 2A.4 ADR) |
| Art. 25-29 | Right to information | `pulsar-dataprotection::access` | (Sprint 2C.2 ADR) |
| Art. 30-32 | Right to rectification + erasure | `pulsar-dataprotection::rtbf` | ADR-0010 spec/rtbf.tla |
| Art. 16-19 | Cross-border data transfers | `pulsar-cloud` driver selection + region-bound adapter discipline per ADR-0002 hexagonal | ADR-0002 |

**Per-framework dossier:** `docs/compliance/frameworks/switzerland-nfadp.md` (lands Sprint 2.6).

### 31. Common Criteria ISO/IEC 15408 — EAL 6+ / EAL 7 (v2.3 NEW — certification target)

**Scope.** Common Criteria is a product-certification framework, not a regulatory framework per se. EAL (Evaluation Assurance Level) 6+ and 7 require **semi-formally verified design** and **formally verified design and tested** respectively. They are the highest CC tiers and align with the NIST FIPS 140-3 Level 2 + DO-178C Level A neighbourhood.

Pulsar Framework does not pursue end-to-end CC certification (which is a multi-million-dollar multi-year process specific to the certified product, not the framework). Instead, Pulsar provides **CC-evaluation-ready artefacts** so downstream applications integrating Pulsar can submit for CC certification with materially less work than from scratch:

**Pulsar-provided artefacts.**

| Artefact | Producing crate / sub-project | CC class |
|----------|-------------------------------|----------|
| Formal specifications (TLA+) | `spec/*.tla` (15 files per Decision 2.59) | ADV_FSP.6 (EAL 6) / ADV_FSP.7 (EAL 7) Functional Specification with complete formal presentation |
| Formal proofs (Creusot contracts on `pulsar-kernel`) | `pulsar-kernel/src/**/*.rs` (Creusot annotations) | ADV_TDS.6 (EAL 6) / ADV_TDS.7 (EAL 7) TOE Design with complete formal presentation |
| Formal proofs (SPARK 2014 GNATprove on capability + audit) | `services/spark-invariants/` (per ADR-0010) | ADV_INT.3 / ADV_TDS.6/7 / supporting evidence at the highest tiers |
| Cryptographic primitives formal verification (HACL\*) | `pulsar-crypto-hacl-bindings/hacl-c/` (per ADR-0009) | FCS_COP (cryptographic operation) — verified primitives |
| Audit chain formal-verification artefacts (Ed25519 + Merkle + RFC 6962 + SPARK append-only proof) | `pulsar-audit` + `services/spark-invariants/audit_chain_append_only.adb` (per ADR-0013) | FAU (security audit) class — verified audit trail |
| Test discipline (100% MC/DC + 99% mutation kill rate) | `pulsar-kernel` + 6 security-controls crates + `pulsar-compliance` + `pulsar-dataprotection` (per ADR-0014) | ATE_DPT.4 (depth — implementation representation) |

**Per-framework dossier:** `docs/compliance/frameworks/common-criteria-eal-6-7.md` (lands Sprint 4.7 alongside the FIPS 140-3 validation pathway).

## v2.2 framework summaries (detailed dossiers under `docs/compliance/frameworks/<name>.md`)

The 22 v2.2 mandatory frameworks + MiCA opt-in have detailed mappings in their per-framework dossiers (landing at the implementing-sprint exit, mostly Sprint 2.6). Highlights:

- **GDPR / UK GDPR** — `pulsar-dataprotection` + `pulsar-consent` + `pulsar-audit` cover Art. 5/6/7/12-23/25/30/32/33/34/35/36/37-39. RtbF two-phase commit (per ADR-0010 `spec/rtbf.tla`) covers Art. 17.
- **HIPAA** — `pulsar-dataprotection` (de-identification + Safe Harbor + Expert Determination methods) + `pulsar-audit` (45 CFR 164.312(b)) + `pulsar-kernel::crypto` (164.312(a)(2)(iv) encryption).
- **PCI-DSS 4.0** — full 12-requirement mapping. Key technical controls: Req. 4 (encryption in transit), Req. 6.5.x (secure SDLC), Req. 8.x (authentication), Req. 10 (logging), Req. 11.x (security testing).
- **PSD2 RTS** + **DORA** — `pulsar-auth::sca` (Strong Customer Authentication) + `pulsar-resilience` + `pulsar-incident` + DORA registers in `pulsar-compliance::dora`.
- **SOC 2** — Trust Service Criteria CC1-CC9 mapped to controls per implementing crate.
- **ISO 27001:2022** — 93 Annex A controls mapped (with focus on A.5.x policies, A.8.x people, A.9.x asset management, A.12.x operational security, A.13.x communications security, A.14.x SDLC, A.18.x compliance).
- **ISO 27017 / 27018 / 27701** — cloud security + cloud PII + privacy management extensions to ISO 27001.
- **NIS2** — Art. 21(2)(a-j) measures + Art. 23 incident reporting.
- **eIDAS 2** — qualified electronic signature + qualified time-stamp + qualified seal mappings to `pulsar-identity-standards` + `pulsar-audit`.
- **Privacy laws (CCPA, LGPD, APPI, PIPL, POPIA, NDB, Mexican LFP, India DPDP)** — common pattern: consent ledger + access right + erasure right + breach notification, mapped per-jurisdiction in the per-framework dossiers.
- **MiCA** (opt-in) — applies only when Pulsar is deployed for crypto-asset service provision; activates `pulsar-mica-attestation` feature flag + adds the relevant audit-trail discriminators.

## Cross-framework synergies

Many controls satisfy multiple frameworks simultaneously:

| Pulsar control | Frameworks satisfied |
|----------------|----------------------|
| Audit chain Ed25519+Merkle+RFC 6962 (per ADR-0013) | GDPR Art. 30/32, HIPAA 164.312(b), PCI-DSS 10.5/10.6, NYDFS 500.6, MAS TRM Ch. 12, APRA CPS 234 para 22-23, DORA Art. 9(2)(b), SOC 2 CC7.2, NIS2 Art. 21(2)(h), nFADP Art. 8 |
| HACL\* formally-verified crypto (per ADR-0009) | NIST FIPS 140-3 L2, FIPS 203/204/205, EU CRA Annex I § 1(d), DORA Art. 9(2), NIST SP 800-53 SC-13, PCI-DSS 4.2.1, eIDAS 2, MAS TRM Ch. 11, Common Criteria FCS_COP |
| Capability token unforgeability (per ADR-0010 SPARK) | DORA Art. 9(2)(c), NYDFS 500.7, MAS TRM Ch. 10, APRA CPS 234 para 22-23, ISO 27001 A.8.2/A.8.5, Common Criteria ADV_TDS.6/7 |
| RtbF two-phase commit (per ADR-0010 `spec/rtbf.tla`) | GDPR Art. 17, UK GDPR Art. 17, CCPA § 1798.105, LGPD Art. 18.VI, India DPDP Act § 12, Quebec Law 25 § 8.1, nFADP Art. 30-32 |
| Hybrid PQC from Sprint 1.1 (per ADR-0012) | NIST FIPS 203/204/205, EU CRA, NIS2, DORA, PSD2, eIDAS 2, MAS TRM Ch. 11 (forward-quantum-readiness) |

## Framework certification + attestation pathways

Some frameworks support formal certification or attestation; others operate via supervisory review. Pulsar's posture:

| Framework | Pathway | Pulsar Foundation pursues? |
|-----------|---------|----------------------------|
| ISO 27001:2022 | UKAS / ANAB-accredited body audit + 3-year cycle | yes — Sprint 4.7 |
| SOC 2 Type II | Independent auditor attestation | yes — Sprint 4.7 |
| PCI-DSS 4.0 | QSA-led assessment | enabling, not pursuing as framework (downstream applications obtain) |
| HIPAA | Self-attestation + risk assessment | enabling |
| NIST FIPS 140-3 Level 2 | CMVP submission via accredited lab | yes — Sprint 4.7 |
| Common Criteria EAL 6+/7 | NIAP / BSI / ANSSI evaluation | enabling (artefacts provided; full certification is per-product) |
| OpenSSF Best Practices Badge — Gold | Self-certification + community audit | yes — Sprint 0.10 |
| OpenSSF Scorecard ≥ 9.0 | Automated scoring | yes — Sprint 0.10 (already wired in `audit.yml`) |
| OpenChain ISO/IEC 5230 | Self-conformance + (optional) third-party audit | yes — Sprint 4.7 |

## Updates + change management

Compliance frameworks evolve. The matrix is reviewed:

- At every MAJOR release boundary (full re-walk of every framework's mapping).
- When a regulatory authority publishes an update (e.g. RBI 2025 cybersecurity-framework update, EU AI Act 2026 implementation phases).
- When a Pulsar architectural change affects a control (e.g. an ADR that supersedes the implementation of a control).
- Per-framework dossiers carry their own changelog; the matrix index here links to the active version of each dossier.
