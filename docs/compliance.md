# Compliance matrix

Pulsar is designed for regulated, mission-critical domains. This document maps Pulsar's built-in capabilities to specific requirements across ten regulatory and standards frameworks relevant to banking, healthcare, legal, and general enterprise environments.

## Legend

| Symbol | Name           | Meaning                                                                           |
| ------ | -------------- | --------------------------------------------------------------------------------- |
| S      | Supported      | Fully addressed by Pulsar's built-in capability with secure defaults              |
| I      | Interface      | Interface-only: Pulsar defines the contract, integrator must implement            |
| O      | Optional       | Partially addressed; application-level configuration or code required to complete |
| M      | Manual         | Architectural support provided; implementation is entirely application-scoped     |
| X      | Not applicable | Not applicable to the framework layer or outside framework scope                  |

Previous versions of this document used Y/P/A/-- which conflated "fully implemented" with "partially implemented." The S/I/O/M/X legend provides clearer accountability for what the framework delivers versus what the integrator must build.

## Frameworks covered

| Abbreviation | Full Name                                                                  | Domain          |
| ------------ | -------------------------------------------------------------------------- | --------------- |
| PSD2         | Payment Services Directive 2 (EU 2015/2366)                                | Banking         |
| PCI DSS v4   | Payment Card Industry Data Security Standard v4.0                          | Payments        |
| NIS2         | Network and Information Security Directive 2 (EU 2022/2555)                | Cross-sector    |
| GDPR         | General Data Protection Regulation (EU 2016/679)                           | Data protection |
| eIDAS        | Electronic Identification, Authentication and Trust Services (EU 910/2014) | Identity        |
| MDR          | Medical Device Regulation (EU 2017/745)                                    | Healthcare      |
| HL7/FHIR     | Health Level Seven / Fast Healthcare Interoperability Resources            | Healthcare      |
| ISO 13485    | Quality Management Systems for Medical Devices                             | Healthcare      |
| SOC 2        | Service Organization Control 2 (AICPA Trust Services Criteria)             | Enterprise      |
| ISO 27001    | Information Security Management Systems (Annex A controls)                 | Enterprise      |
| ISO 42001    | AI Management Systems (ISO/IEC 42001:2023)                                 | AI governance   |

## Compliance matrix

### 1. Cryptography and key management

| Requirement Area              | Pulsar Capability                                                | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ----------------------------- | ---------------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Encryption at rest            | `EncryptorInterface` (XSalsa20-Poly1305 via libsodium)           | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| Encryption in transit         | Secure cookie flags (`Secure`, `HttpOnly`, `SameSite=Strict`)    | O    | O          | O    | O    | O     | O   | O        | O         | O     | O         |
| Key derivation                | Single `PULSAR_MASTER_KEY` with KDF domain separation (ADR-0006) | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| Key rotation                  | `KeyRingInterface` with key ID (`kid`) tracking on audit entries | S    | S          | S    | O    | S     | O   | O        | O         | S     | S         |
| Approved algorithms only      | libsodium-only policy; no external crypto packages               | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| HMAC / message authentication | keyed BLAKE2b via `HmacInterface`                                | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| Password hashing              | Argon2id (PHP native `password_hash`) [^1]                       | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |

[^1]: Password hashing uses PHP's native `password_hash($password, PASSWORD_ARGON2ID)` rather than `sodium_crypto_pwhash_str()`. This is an exception to the libsodium-only policy (ADR-0006) because PHP's built-in Argon2id implementation provides equivalent security with better upgrade ergonomics via `password_needs_rehash()`.

### 2. Authentication and access control

| Requirement Area               | Pulsar Capability                                                  | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ------------------------------ | ------------------------------------------------------------------ | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Multi-factor authentication    | `AuthConfig` with TOTP two-factor support                          | S    | S          | S    | O    | S     | O   | O        | O         | S     | S         |
| Session management             | `SessionInterface` with secure defaults, regeneration on privilege | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| CSRF protection                | `CsrfMiddleware` with synchronizer token pattern                   | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| Role-based authorization       | `AuthConfig` roles and permissions model                           | S    | S          | S    | O    | O     | S   | S        | S         | S     | S         |
| Strong customer authentication | Extensible guard system (`session`, `token`) + 2FA                 | S    | O          | O    | X    | S     | X   | X        | X         | O     | O         |
| Rate limiting                  | `RateLimitConfig` with configurable windows                        | S    | S          | S    | O    | S     | O   | O        | O         | S     | S         |

### 3. Audit logging and accountability

| Requirement Area        | Pulsar Capability                                              | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ----------------------- | -------------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Immutable audit trail   | `AuditEntry` with keyed BLAKE2b chain integrity (ADR-0008)     | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| Structured audit events | `AuditEvent` enum (auth, data access, config change, etc.)     | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| Tamper evidence         | HMAC chain where each entry covers all fields + previous HMAC  | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| Audit log retention     | `RetentionPolicyInterface` + config (7-year default for audit) | S    | S          | S    | S    | O     | S   | S        | S         | S     | S         |
| Actor attribution       | Actor, action, resource fields on every `AuditEntry`           | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| Chain verification      | `AuditChainVerifier` validates chain integrity on demand       | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |

### 4. Data protection and privacy

| Requirement Area            | Pulsar Capability                                                  | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| --------------------------- | ------------------------------------------------------------------ | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Data retention policies     | `RetentionPolicyInterface` with configurable per-category periods  | S    | S          | S    | S    | O     | S   | S        | S         | S     | S         |
| Automated data purging      | `DataPurgeInterface` with batch size + dry-run support             | I    | I          | I    | I    | X     | I   | I        | I         | I     | I         |
| Consent management          | `ConsentManagerInterface` (grant, revoke, query)                   | I    | X          | X    | I    | I     | I   | I        | I         | I     | I         |
| Consent records             | `ConsentRecordInterface` with subject, purpose, policy version     | X    | X          | X    | I    | I     | I   | I        | I         | I     | I         |
| Right to erasure            | `DataPurgeInterface` supports targeted purging per policy          | X    | X          | X    | I    | X     | X   | I        | X         | I     | I         |
| Data minimization           | Architecture supports explicit data scoping via retention policies | O    | O          | O    | S    | X     | S   | S        | S         | O     | O         |
| Sensitive parameter masking | `#[SensitiveParameter]` on key material in crypto interfaces       | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |

### 5. Incident management

| Requirement Area            | Pulsar Capability                                                   | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| --------------------------- | ------------------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Incident reporting          | `IncidentReporterInterface` (report, find, query)                   | I    | I          | I    | I    | I     | I   | I        | I         | I     | I         |
| Severity classification     | `IncidentSeverity` enum (low, medium, high, critical)               | S    | S          | S    | S    | O     | S   | O        | S         | S     | S         |
| Incident records            | `IncidentInterface` with structured metadata, source, timestamp     | I    | I          | I    | I    | I     | I   | I        | I         | I     | I         |
| Incident triage             | Severity-filtered queries via `IncidentReporterInterface::recent()` | I    | I          | I    | I    | X     | I   | X        | I         | I     | I         |
| Breach notification support | Incident metadata supports breach detail capture for regulators     | M    | M          | M    | M    | X     | M   | X        | M         | M     | M         |

### 6. Observability and monitoring

| Requirement Area       | Pulsar Capability                                        | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ---------------------- | -------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Structured logging     | First-party structured logging (PSR-3 compatible)        | S    | S          | S    | O    | O     | S   | S        | S         | S     | S         |
| Metrics collection     | `MetricRegistry` with optional standards-based exporters | O    | O          | S    | X    | X     | O   | O        | O         | S     | S         |
| Distributed tracing    | Spans + context propagation (Fiber-scoped via `WeakMap`) | O    | O          | S    | X    | X     | O   | O        | O         | S     | S         |
| Error tracking         | First-party error grouping and reporting                 | O    | O          | S    | X    | X     | O   | O        | O         | S     | S         |
| Performance monitoring | Performance budgets with regression thresholds           | X    | O          | O    | X    | X     | O   | X        | O         | O     | O         |

### 7. Resilience and availability

| Requirement Area             | Pulsar Capability                                   | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ---------------------------- | --------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Circuit breaker              | Resilience module with configurable circuit breaker | S    | O          | S    | X    | O     | S   | O        | S         | S     | S         |
| Retry with backoff           | Resilience module retry policies                    | S    | O          | S    | X    | O     | S   | O        | S         | S     | S         |
| Idempotency                  | `Idempotency` module for safe request replays       | S    | O          | O    | X    | O     | O   | O        | O         | S     | S         |
| Queue and worker supervision | `Queue`, `Supervisor` modules with signal handling  | O    | O          | S    | X    | X     | O   | O        | O         | S     | S         |

### 8. Configuration and deployment

| Requirement Area            | Pulsar Capability                                       | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| --------------------------- | ------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Typed configuration         | Readonly config DTOs with `fromArray()` factories       | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| Environment-based overrides | `Environment` DTO for per-environment config            | S    | S          | S    | S    | S     | S   | S        | S         | S     | S         |
| Feature flags               | `FeatureFlag` module for controlled rollouts            | O    | O          | O    | X    | X     | S   | O        | S         | O     | O         |
| Deployment integrity        | `Integrity` module for artifact verification            | O    | S          | S    | X    | O     | S   | O        | S         | S     | S         |
| Deploy severity gates       | `Deploy` module with severity-based deployment blocking | O    | O          | S    | X    | X     | S   | X        | S         | S     | S         |

### 9. Multi-tenancy and isolation

| Requirement Area | Pulsar Capability                        | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ---------------- | ---------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Tenant isolation | `Tenancy` module with per-tenant context | S    | S          | O    | S    | O     | O   | S        | O         | S     | S         |
| Data segregation | Tenant-scoped database and storage       | S    | S          | O    | S    | O     | O   | S        | O         | S     | S         |

### 10. Extensibility and modularity

| Requirement Area            | Pulsar Capability                                                | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| --------------------------- | ---------------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Module boundary enforcement | Deptrac + `#[Api]`/`#[Internal]` boundary checks (ADR-0002/0009) | O    | O          | S    | O    | X     | S   | O        | S         | S     | S         |
| Extension lifecycle         | Six-phase lifecycle via `pulsar.json` manifests (ADR-0004)       | X    | X          | O    | X    | X     | S   | O        | S         | O     | O         |
| API stability tracking      | `#[Api(since)]` + public API snapshot + `PublicApiSnapshotTest`  | X    | X          | X    | X    | X     | S   | O        | S         | O     | O         |

## Per-framework summary

### PSD2 (Payment services directive 2)

**Key articles addressed:**

- **Art. 4 (Strong Customer Authentication)**: Multi-factor authentication via `AuthConfig` with TOTP 2FA; extensible guard system.
- **Art. 45 (Record keeping)**: Audit trail with 7-year retention policy; HMAC chain integrity.
- **Art. 73 (Incident reporting)**: `IncidentReporterInterface` with severity classification and structured metadata (interface-only; integrator must implement).
- **Art. 95 (Security measures)**: libsodium-only cryptography, session hardening, CSRF protection, rate limiting.

### PCI DSS v4.0

**Key requirements addressed:**

- **Req. 3 (Protect stored account data)**: Encryption at rest via `EncryptorInterface`, key derivation with domain separation.
- **Req. 7-8 (Access control / Authentication)**: Role-based authorization, session management, 2FA support.
- **Req. 10 (Log and monitor)**: Immutable audit logging with tamper evidence, 7-year retention.
- **Req. 10.7 (Retain audit trail history)**: Configurable retention policies via `RetentionPolicyInterface`.
- **Req. 11 (Test security regularly)**: Boundary enforcement, integrity verification, deployment severity gates.
- **Req. 12.10 (Incident response)**: `IncidentReporterInterface` with severity-based triage (interface-only; integrator must implement).

### NIS2 (Network and information security directive 2)

**Key articles addressed:**

- **Art. 21 (Risk management measures)**: Cryptography, access control, resilience (circuit breaker, retry), incident management.
- **Art. 23 (Reporting obligations)**: `IncidentReporterInterface` supports severity classification and metadata capture for regulatory notification (interface-only; integrator must implement).
- **Art. 24 (Monitoring)**: Structured logging, metrics, tracing, error tracking.

### GDPR (general data protection regulation)

**Key articles addressed:**

- **Art. 5(1)(e) (Storage limitation)**: `RetentionPolicyInterface` with automated purging via `DataPurgeInterface` (purge interface is interface-only; integrator must implement).
- **Art. 7 (Conditions for consent)**: `ConsentManagerInterface` with purpose-specific grant/revoke and policy versioning (interface-only; integrator must implement).
- **Art. 17 (Right to erasure)**: `DataPurgeInterface` supports targeted data deletion (interface-only; integrator must implement).
- **Art. 25 (Data protection by design)**: Encryption, `#[SensitiveParameter]` masking, minimal data defaults.
- **Art. 30 (Records of processing activities)**: Audit trail with actor, action, resource attribution.
- **Art. 32 (Security of processing)**: libsodium cryptography, session hardening, access controls.
- **Art. 33-34 (Breach notification)**: `IncidentReporterInterface` captures breach details for supervisor notification (interface-only; integrator must implement).

### eIDAS (electronic identification and trust services)

**Key articles addressed:**

- **Art. 8 (Assurance levels)**: Multi-factor authentication support for substantial/high assurance.
- **Art. 19 (Security requirements for trust service providers)**: Cryptographic key management, audit logging.
- **Art. 24 (Requirements for qualified TSPs)**: Incident reporting, integrity verification.

### MDR (Medical device regulation EU 2017/745)

**Key articles addressed:**

- **Art. 10(8) (Record retention)**: 10-year default retention for health records.
- **Annex I, Ch. III, 17 (Software requirements)**: Module boundary enforcement, extension lifecycle, API stability tracking.
- **Art. 87 (Vigilance)**: Incident reporting and severity classification.
- **Art. 10(9) (Quality management)**: Feature flags for controlled rollouts, deployment severity gates.

### HL7/FHIR

**Relevant areas addressed:**

- **Security and Privacy Module**: Authentication, authorization, audit events, encryption.
- **Audit Event resource mapping**: `AuditEntry` fields (actor, action, resource, outcome) align with FHIR AuditEvent.
- **Consent resource mapping**: `ConsentManagerInterface` operations map to FHIR Consent resource lifecycle (interface-only; integrator must implement).
- **Data retention**: Configurable per-category retention with regulatory basis tracking.

### ISO 13485 (quality management systems for medical devices)

**Key clauses addressed:**

- **Clause 4.2.5 (Control of records)**: Immutable audit trail, configurable retention periods with legal basis.
- **Clause 7.5.1 (Control of production)**: Feature flags, deployment gates, integrity verification.
- **Clause 8.2.3 (Monitoring and measurement)**: Structured logging, metrics, performance budgets.
- **Clause 8.5.1 (Post-delivery activities)**: Incident reporting with severity triage.

### SOC 2 (trust services criteria)

**Key criteria addressed:**

- **CC6.1 (Logical and physical access controls)**: Authentication, RBAC, session management, rate limiting.
- **CC6.7 (Data integrity in transit/at rest)**: libsodium encryption, HMAC chain integrity.
- **CC7.2 (System monitoring)**: Structured logging, metrics, tracing, error tracking.
- **CC7.3 (Incident detection and response)**: `IncidentReporterInterface`, severity classification (interface-only; integrator must implement).
- **CC7.4 (Incident management and recovery)**: Incident records with structured metadata for investigation.
- **CC8.1 (Change management)**: API stability tracking, boundary enforcement, deployment severity gates.
- **A1.2 (Availability - recovery)**: Resilience module (circuit breaker, retry), queue/supervisor for job processing.

### ISO 27001 (information security management systems)

**Key Annex A controls addressed:**

- **A.8.2 (Privileged access rights)**: Role-based authorization with super-role support.
- **A.8.3 (Information access restriction)**: Tenant isolation, RBAC, session management.
- **A.8.5 (Secure authentication)**: Multi-factor authentication, session regeneration, password hashing.
- **A.8.24 (Use of cryptography)**: libsodium-only policy, KDF domain separation, key ID tracking.
- **A.8.15 (Logging)**: Immutable structured audit trail with HMAC chain integrity.
- **A.5.24-28 (Incident management)**: `IncidentReporterInterface` with severity, metadata, and triage support (interface-only; integrator must implement).
- **A.8.10 (Information deletion)**: `DataPurgeInterface` with retention policies and automated purging (interface-only; integrator must implement).
- **A.12.4 (Logging and monitoring)**: Structured logging, metrics, tracing, 365-day access log retention.

### ISO 42001 (AI management systems)

Pulsar is the first PHP framework to provide ISO/IEC 42001:2023 AI governance controls via the `pulsar/ai-governance` extension. This extension provides framework-level infrastructure for organizations deploying AI systems in regulated environments.

**Key clauses addressed:**

- **Clause 6.1.2 (AI Risk Assessment)**: `AiImpactAssessmentInterface` with structured findings across six impact categories (fairness, transparency, accountability, privacy, safety, security), severity classification, and risk scoring.
- **Clause 7.5 (Documented Information)**: `ModelCard` DTO documenting model capabilities, limitations, known biases, training data sources, performance metrics, and ethical considerations.
- **Clause 8.2 (AI System Impact Assessment)**: Structured assessment with actionable recommendations per impact category.
- **Clause 8.3 (Data for AI Systems)**: `AiDataGovernanceInterface` with training data provenance tracking (source, license, transformations), data quality reports (completeness, accuracy, consistency), and consent verification.
- **Clause 8.4 (AI System Life Cycle)**: `AiLifecycleManagerInterface` with six lifecycle stages (development, testing, staging, production, deprecated, retired), deployment gates, monitoring hooks, and rollback capability.
- **Clause 9.1 (Monitoring and Measurement)**: `MonitoringHookInterface` for production model health checks; `AiAuditLoggerInterface` for tamper-evident logging of AI events (model invocations, decisions, human overrides, bias detection).
- **Clause 9.2 (Internal Audit)**: AI-specific audit event taxonomy integrated with the core HMAC-chained audit trail.
- **Annex A.5 (Data for AI Systems)**: `DataProvenance` DTO with source, license, consent tracking, and transformation history.
- **Annex A.6 (AI System Life Cycle)**: Model registry, deployment gates, and lifecycle transition controls.
- **Annex A.8 (Transparency and Explainability)**: `ExplainabilityInterface` with structured explanations (decision factors, confidence scores, alternatives considered).

**What the integrator must provide:**

- Concrete AI provider integrations (LLM API clients, classifier wrappers, etc.)
- Production-grade persistent stores for model registry, data governance, and explainability
- Domain-specific deployment gates and monitoring hooks
- Organizational AI policy documentation
- Model card content for each deployed AI system

## Capability-to-source mapping

| Capability            | Primary Source Files                                                                                 |
| --------------------- | ---------------------------------------------------------------------------------------------------- |
| Encryption / Key mgmt | `src/Security/Crypto/EncryptorInterface.php`, `src/Security/Crypto/Hmac.php`, `config/security.php`  |
| Session security      | `src/Security/Session/SessionInterface.php`, `src/Config/SessionConfig.php`                          |
| CSRF protection       | `src/Security/Csrf/`, `src/Config/CsrfConfig.php`                                                    |
| Audit logging         | `src/Security/Audit/AuditEntry.php`, `src/Security/Audit/AuditChainVerifier.php`                     |
| Data retention        | `src/DataProtection/RetentionPolicyInterface.php`, `src/DataProtection/DataPurgeInterface.php`       |
| Consent management    | `src/DataProtection/ConsentManagerInterface.php`, `src/DataProtection/ConsentRecordInterface.php`    |
| Incident management   | `src/Security/Incident/IncidentReporterInterface.php`, `src/Security/Incident/IncidentInterface.php` |
| Resilience            | `src/Resilience/`                                                                                    |
| Observability         | `src/Observability/`                                                                                 |
| Tenancy               | `src/Tenancy/`                                                                                       |
| Boundary enforcement  | `tools/php/deptrac.yaml`, `scripts/boundary_check.php`                                               |
| AI governance         | `extensions/ai-governance/src/`                                                                      |

## Notes

1. **Framework vs. application responsibility**: Pulsar provides the interfaces, secure defaults, and enforcement mechanisms. Application developers are responsible for implementing domain-specific policies (e.g., defining which data categories apply, wiring consent flows into their UI, configuring retention periods for their jurisdiction).

2. **Regulatory currency**: This matrix reflects requirements as of Pulsar 1.0.0-rc.11. Regulatory frameworks evolve; consult current regulation texts and qualified legal counsel for compliance certification.

3. **Certification scope**: Framework-level controls alone do not constitute compliance. A complete compliance posture requires infrastructure controls, organizational policies, personnel training, and regular audits beyond what any framework can provide.
