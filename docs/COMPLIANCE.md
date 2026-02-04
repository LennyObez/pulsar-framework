# Compliance Matrix

Pulsar is designed for regulated, mission-critical domains. This document maps Pulsar's built-in capabilities to specific requirements across ten regulatory and standards frameworks relevant to banking, healthcare, legal, and general enterprise environments.

## Legend

| Symbol | Meaning                                                               |
| ------ | --------------------------------------------------------------------- |
| Y      | Fully addressed by Pulsar's built-in capability                       |
| P      | Partially addressed; application-level configuration or code required |
| A      | Architectural support provided; implementation is application-scoped  |
| --     | Not applicable to the framework layer                                 |

## Frameworks Covered

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

## Compliance Matrix

### 1. Cryptography and Key Management

| Requirement Area              | Pulsar Capability                                                | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ----------------------------- | ---------------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Encryption at rest            | `EncryptorInterface` (XSalsa20-Poly1305 via libsodium)           | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| Encryption in transit         | Secure cookie flags (`Secure`, `HttpOnly`, `SameSite=Strict`)    | P    | P          | P    | P    | P     | P   | P        | P         | P     | P         |
| Key derivation                | Single `PULSAR_MASTER_KEY` with KDF domain separation (ADR-0006) | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| Key rotation                  | `KeyRingInterface` with key ID (`kid`) tracking on audit entries | Y    | Y          | Y    | P    | Y     | P   | P        | P         | Y     | Y         |
| Approved algorithms only      | libsodium-only policy; no external crypto packages               | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| HMAC / message authentication | BLAKE2b HMAC via `HmacInterface`                                 | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| Password hashing              | Argon2id (libsodium)                                             | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |

### 2. Authentication and Access Control

| Requirement Area               | Pulsar Capability                                                  | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ------------------------------ | ------------------------------------------------------------------ | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Multi-factor authentication    | `AuthConfig` with TOTP two-factor support                          | Y    | Y          | Y    | P    | Y     | P   | P        | P         | Y     | Y         |
| Session management             | `SessionInterface` with secure defaults, regeneration on privilege | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| CSRF protection                | `CsrfMiddleware` with double-submit token                          | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| Role-based authorization       | `AuthConfig` roles and permissions model                           | Y    | Y          | Y    | P    | P     | Y   | Y        | Y         | Y     | Y         |
| Strong customer authentication | Extensible guard system (`session`, `token`) + 2FA                 | Y    | P          | P    | --   | Y     | --  | --       | --        | P     | P         |
| Rate limiting                  | `RateLimitConfig` with configurable windows                        | Y    | Y          | Y    | P    | Y     | P   | P        | P         | Y     | Y         |

### 3. Audit Logging and Accountability

| Requirement Area        | Pulsar Capability                                              | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ----------------------- | -------------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Immutable audit trail   | `AuditEntry` with HMAC-BLAKE2b chain integrity (ADR-0008)      | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| Structured audit events | `AuditEvent` enum (auth, data access, config change, etc.)     | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| Tamper evidence         | HMAC chain where each entry covers all fields + previous HMAC  | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| Audit log retention     | `RetentionPolicyInterface` + config (7-year default for audit) | Y    | Y          | Y    | Y    | P     | Y   | Y        | Y         | Y     | Y         |
| Actor attribution       | Actor, action, resource fields on every `AuditEntry`           | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| Chain verification      | `AuditChainVerifier` validates chain integrity on demand       | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |

### 4. Data Protection and Privacy

| Requirement Area            | Pulsar Capability                                                  | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| --------------------------- | ------------------------------------------------------------------ | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Data retention policies     | `RetentionPolicyInterface` with configurable per-category periods  | Y    | Y          | Y    | Y    | P     | Y   | Y        | Y         | Y     | Y         |
| Automated data purging      | `DataPurgeInterface` with batch size + dry-run support             | P    | Y          | P    | Y    | --    | P   | P        | P         | Y     | Y         |
| Consent management          | `ConsentManagerInterface` (grant, revoke, query)                   | P    | --         | --   | Y    | P     | Y   | Y        | P         | P     | P         |
| Consent records             | `ConsentRecordInterface` with subject, purpose, policy version     | --   | --         | --   | Y    | P     | Y   | Y        | P         | P     | P         |
| Right to erasure            | `DataPurgeInterface` supports targeted purging per policy          | --   | --         | --   | Y    | --    | --  | P        | --        | P     | P         |
| Data minimization           | Architecture supports explicit data scoping via retention policies | P    | P          | P    | Y    | --    | Y   | Y        | Y         | P     | P         |
| Sensitive parameter masking | `#[SensitiveParameter]` on key material in crypto interfaces       | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |

### 5. Incident Management

| Requirement Area            | Pulsar Capability                                                   | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| --------------------------- | ------------------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Incident reporting          | `IncidentReporterInterface` (report, find, query)                   | Y    | Y          | Y    | Y    | P     | Y   | P        | Y         | Y     | Y         |
| Severity classification     | `IncidentSeverity` enum (low, medium, high, critical)               | Y    | Y          | Y    | Y    | P     | Y   | P        | Y         | Y     | Y         |
| Incident records            | `IncidentInterface` with structured metadata, source, timestamp     | Y    | Y          | Y    | Y    | P     | Y   | P        | Y         | Y     | Y         |
| Incident triage             | Severity-filtered queries via `IncidentReporterInterface::recent()` | P    | P          | Y    | P    | --    | P   | --       | P         | Y     | Y         |
| Breach notification support | Incident metadata supports breach detail capture for regulators     | A    | A          | Y    | Y    | --    | A   | --       | A         | A     | A         |

### 6. Observability and Monitoring

| Requirement Area       | Pulsar Capability                                        | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ---------------------- | -------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Structured logging     | First-party structured logging (PSR-3 compatible)        | Y    | Y          | Y    | P    | P     | Y   | Y        | Y         | Y     | Y         |
| Metrics collection     | `MetricRegistry` with optional standards-based exporters | P    | P          | Y    | --   | --    | P   | P        | P         | Y     | Y         |
| Distributed tracing    | Spans + context propagation (Fiber-scoped via `WeakMap`) | P    | P          | Y    | --   | --    | P   | P        | P         | Y     | Y         |
| Error tracking         | First-party error grouping and reporting                 | P    | P          | Y    | --   | --    | P   | P        | P         | Y     | Y         |
| Performance monitoring | Performance budgets with regression thresholds           | --   | P          | P    | --   | --    | P   | --       | P         | P     | P         |

### 7. Resilience and Availability

| Requirement Area             | Pulsar Capability                                   | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ---------------------------- | --------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Circuit breaker              | Resilience module with configurable circuit breaker | Y    | P          | Y    | --   | P     | Y   | P        | Y         | Y     | Y         |
| Retry with backoff           | Resilience module retry policies                    | Y    | P          | Y    | --   | P     | Y   | P        | Y         | Y     | Y         |
| Idempotency                  | `Idempotency` module for safe request replays       | Y    | P          | P    | --   | P     | P   | P        | P         | Y     | Y         |
| Queue and worker supervision | `Queue`, `Supervisor` modules with signal handling  | P    | P          | Y    | --   | --    | P   | P        | P         | Y     | Y         |

### 8. Configuration and Deployment

| Requirement Area            | Pulsar Capability                                       | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| --------------------------- | ------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Typed configuration         | Readonly config DTOs with `fromArray()` factories       | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| Environment-based overrides | `Environment` DTO for per-environment config            | Y    | Y          | Y    | Y    | Y     | Y   | Y        | Y         | Y     | Y         |
| Feature flags               | `FeatureFlag` module for controlled rollouts            | P    | P          | P    | --   | --    | Y   | P        | Y         | P     | P         |
| Deployment integrity        | `Integrity` module for artifact verification            | P    | Y          | Y    | --   | P     | Y   | P        | Y         | Y     | Y         |
| Deploy severity gates       | `Deploy` module with severity-based deployment blocking | P    | P          | Y    | --   | --    | Y   | --       | Y         | Y     | Y         |

### 9. Multi-tenancy and Isolation

| Requirement Area | Pulsar Capability                        | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| ---------------- | ---------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Tenant isolation | `Tenancy` module with per-tenant context | Y    | Y          | P    | Y    | P     | P   | Y        | P         | Y     | Y         |
| Data segregation | Tenant-scoped database and storage       | Y    | Y          | P    | Y    | P     | P   | Y        | P         | Y     | Y         |

### 10. Extensibility and Modularity

| Requirement Area            | Pulsar Capability                                                | PSD2 | PCI DSS v4 | NIS2 | GDPR | eIDAS | MDR | HL7/FHIR | ISO 13485 | SOC 2 | ISO 27001 |
| --------------------------- | ---------------------------------------------------------------- | ---- | ---------- | ---- | ---- | ----- | --- | -------- | --------- | ----- | --------- |
| Module boundary enforcement | Deptrac + `#[Api]`/`#[Internal]` boundary checks (ADR-0002/0009) | P    | P          | Y    | P    | --    | Y   | P        | Y         | Y     | Y         |
| Extension lifecycle         | Six-phase lifecycle via `pulsar.json` manifests (ADR-0004)       | --   | --         | P    | --   | --    | Y   | P        | Y         | P     | P         |
| API stability tracking      | `#[Api(since)]` + public API snapshot + `PublicApiSnapshotTest`  | --   | --         | --   | --   | --    | Y   | P        | Y         | P     | P         |

## Per-Framework Summary

### PSD2 (Payment Services Directive 2)

**Key articles addressed:**

- **Art. 4 (Strong Customer Authentication)**: Multi-factor authentication via `AuthConfig` with TOTP 2FA; extensible guard system.
- **Art. 45 (Record keeping)**: Audit trail with 7-year retention policy; HMAC chain integrity.
- **Art. 73 (Incident reporting)**: `IncidentReporterInterface` with severity classification and structured metadata.
- **Art. 95 (Security measures)**: libsodium-only cryptography, session hardening, CSRF protection, rate limiting.

### PCI DSS v4.0

**Key requirements addressed:**

- **Req. 3 (Protect stored account data)**: Encryption at rest via `EncryptorInterface`, key derivation with domain separation.
- **Req. 7-8 (Access control / Authentication)**: Role-based authorization, session management, 2FA support.
- **Req. 10 (Log and monitor)**: Immutable audit logging with tamper evidence, 7-year retention.
- **Req. 10.7 (Retain audit trail history)**: Configurable retention policies via `RetentionPolicyInterface`.
- **Req. 11 (Test security regularly)**: Boundary enforcement, integrity verification, deployment severity gates.
- **Req. 12.10 (Incident response)**: `IncidentReporterInterface` with severity-based triage.

### NIS2 (Network and Information Security Directive 2)

**Key articles addressed:**

- **Art. 21 (Risk management measures)**: Cryptography, access control, resilience (circuit breaker, retry), incident management.
- **Art. 23 (Reporting obligations)**: `IncidentReporterInterface` supports severity classification and metadata capture for regulatory notification.
- **Art. 24 (Monitoring)**: Structured logging, metrics, tracing, error tracking.

### GDPR (General Data Protection Regulation)

**Key articles addressed:**

- **Art. 5(1)(e) (Storage limitation)**: `RetentionPolicyInterface` with automated purging via `DataPurgeInterface`.
- **Art. 7 (Conditions for consent)**: `ConsentManagerInterface` with purpose-specific grant/revoke and policy versioning.
- **Art. 17 (Right to erasure)**: `DataPurgeInterface` supports targeted data deletion.
- **Art. 25 (Data protection by design)**: Encryption, `#[SensitiveParameter]` masking, minimal data defaults.
- **Art. 30 (Records of processing activities)**: Audit trail with actor, action, resource attribution.
- **Art. 32 (Security of processing)**: libsodium cryptography, session hardening, access controls.
- **Art. 33-34 (Breach notification)**: `IncidentReporterInterface` captures breach details for supervisor notification.

### eIDAS (Electronic Identification and Trust Services)

**Key articles addressed:**

- **Art. 8 (Assurance levels)**: Multi-factor authentication support for substantial/high assurance.
- **Art. 19 (Security requirements for trust service providers)**: Cryptographic key management, audit logging.
- **Art. 24 (Requirements for qualified TSPs)**: Incident reporting, integrity verification.

### MDR (Medical Device Regulation EU 2017/745)

**Key articles addressed:**

- **Art. 10(8) (Record retention)**: 10-year default retention for health records.
- **Annex I, Ch. III, 17 (Software requirements)**: Module boundary enforcement, extension lifecycle, API stability tracking.
- **Art. 87 (Vigilance)**: Incident reporting and severity classification.
- **Art. 10(9) (Quality management)**: Feature flags for controlled rollouts, deployment severity gates.

### HL7/FHIR

**Relevant areas addressed:**

- **Security and Privacy Module**: Authentication, authorization, audit events, encryption.
- **Audit Event resource mapping**: `AuditEntry` fields (actor, action, resource, outcome) align with FHIR AuditEvent.
- **Consent resource mapping**: `ConsentManagerInterface` operations map to FHIR Consent resource lifecycle.
- **Data retention**: Configurable per-category retention with regulatory basis tracking.

### ISO 13485 (Quality Management Systems for Medical Devices)

**Key clauses addressed:**

- **Clause 4.2.5 (Control of records)**: Immutable audit trail, configurable retention periods with legal basis.
- **Clause 7.5.1 (Control of production)**: Feature flags, deployment gates, integrity verification.
- **Clause 8.2.3 (Monitoring and measurement)**: Structured logging, metrics, performance budgets.
- **Clause 8.5.1 (Post-delivery activities)**: Incident reporting with severity triage.

### SOC 2 (Trust Services Criteria)

**Key criteria addressed:**

- **CC6.1 (Logical and physical access controls)**: Authentication, RBAC, session management, rate limiting.
- **CC6.7 (Data integrity in transit/at rest)**: libsodium encryption, HMAC chain integrity.
- **CC7.2 (System monitoring)**: Structured logging, metrics, tracing, error tracking.
- **CC7.3 (Incident detection and response)**: `IncidentReporterInterface`, severity classification.
- **CC7.4 (Incident management and recovery)**: Incident records with structured metadata for investigation.
- **CC8.1 (Change management)**: API stability tracking, boundary enforcement, deployment severity gates.
- **A1.2 (Availability — recovery)**: Resilience module (circuit breaker, retry), queue/supervisor for job processing.

### ISO 27001 (Information Security Management Systems)

**Key Annex A controls addressed:**

- **A.8.2 (Privileged access rights)**: Role-based authorization with super-role support.
- **A.8.3 (Information access restriction)**: Tenant isolation, RBAC, session management.
- **A.8.5 (Secure authentication)**: Multi-factor authentication, session regeneration, password hashing.
- **A.8.24 (Use of cryptography)**: libsodium-only policy, KDF domain separation, key ID tracking.
- **A.8.15 (Logging)**: Immutable structured audit trail with HMAC chain integrity.
- **A.5.24-28 (Incident management)**: `IncidentReporterInterface` with severity, metadata, and triage support.
- **A.8.10 (Information deletion)**: `DataPurgeInterface` with retention policies and automated purging.
- **A.12.4 (Logging and monitoring)**: Structured logging, metrics, tracing, 365-day access log retention.

## Capability-to-Source Mapping

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

## Notes

1. **Framework vs. application responsibility**: Pulsar provides the interfaces, secure defaults, and enforcement mechanisms. Application developers are responsible for implementing domain-specific policies (e.g., defining which data categories apply, wiring consent flows into their UI, configuring retention periods for their jurisdiction).

2. **Regulatory currency**: This matrix reflects requirements as of Pulsar 1.0.0-rc.7. Regulatory frameworks evolve; consult current regulation texts and qualified legal counsel for compliance certification.

3. **Certification scope**: Framework-level controls alone do not constitute compliance. A complete compliance posture requires infrastructure controls, organizational policies, personnel training, and regular audits beyond what any framework can provide.
