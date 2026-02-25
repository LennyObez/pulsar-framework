# Zero-Trust Architecture - Regulatory Compliance Mapping

This document maps Pulsar's zero-trust architecture to applicable regulatory frameworks and security standards. It identifies how the framework's controls support compliance requirements in regulated domains (banking, healthcare, legal).

**Disclaimer**: This document describes control coverage provided by the framework. It does not constitute a compliance certification. Organizations must perform their own compliance assessments, engage qualified auditors, and implement operational procedures appropriate to their regulatory obligations. The framework provides controls and evidence generation capabilities; compliance is achieved through the combination of technical controls, operational procedures, and organizational governance.

---

## 1. NIST SP 800-207 zero-trust architecture - tenet mapping

NIST Special Publication 800-207 defines seven tenets for zero-trust architecture. The following maps each tenet to Pulsar's zero-trust module components.

### Tenet 1: All data sources and computing services are considered resources

> _"A network may be composed of multiple classes of devices. [...] A zero trust architecture (ZTA) considers all data sources and computing services as resources."_

**Control coverage in Pulsar**:

- **Entrypoint-scoped enforcement**: The zero-trust module applies policy evaluation at all framework entrypoints - HTTP/API requests, privileged CLI commands, and background jobs accessing privileged resources. Each entrypoint is treated as a resource boundary requiring authorization.
- **Resource-level policy rules**: `PolicyRule` definitions bind to specific resources and actions (e.g., payment endpoints, PHI access endpoints, admin CLI commands). Access decisions are made per resource, not per network zone.
- **No implicit trust for internal services**: Background jobs re-evaluate authorization at execution time, preventing stale or escalated privileges from persisting across asynchronous boundaries.

**Relevant components**: `ZeroTrustMiddleware`, `PolicyRule`, `PolicyEngine`

---

### Tenet 2: All communication is secured regardless of network location

> _"Network location alone does not imply trust. [...] All communication should be done in a secure manner regardless of network location."_

**Control coverage in Pulsar**:

- **Network-agnostic claims**: The `NetworkSignal` provider produces claims such as `NetworkZone`, `TorExit`, and `KnownProxy`. These claims inform policy decisions but do not grant implicit trust. A request originating from an internal network zone is still subject to full policy evaluation.
- **Claims-based decisions, not perimeter-based**: The policy engine evaluates the complete claim set for every request. Network location is one signal among many - it cannot substitute for identity verification or device attestation.
- **Transport-layer controls**: Pulsar's `SecurityHeadersMiddleware` enforces HSTS, preventing TLS downgrade. Session cookies are marked `Secure` and `SameSite=Strict` (per the session threat model). These controls operate independently of network topology.

**Relevant components**: `NetworkSignal`, `PolicyEngine`, `SecurityHeadersMiddleware`, session security controls

---

### Tenet 3: Access to individual enterprise resources is granted on a per-session basis

> _"Trust in the requester is evaluated before the access is granted. Access should also be granted with the least privileges needed to complete the task."_

**Control coverage in Pulsar**:

- **Per-session claim evaluation**: `ZeroTrustMiddleware` evaluates claims on every request within a session. Access decisions are not cached from previous sessions or inherited from prior authentication events.
- **Continuous verification**: The `ContinuousVerification` component re-evaluates claims during an active session, detecting changes in trust posture (e.g., device becomes unrecognized, geolocation anomaly emerges mid-session).
- **Session-scoped claim snapshots**: Each policy decision produces a `ClaimSet` snapshot tied to the session and request context. This snapshot is audit-logged, providing per-session evidence of the trust basis for each access decision.
- **Least privilege via policy rules**: Policy rules can require specific claims per resource, supporting minimum-necessary-access patterns. A session authenticated for general access does not automatically receive access to privileged resources (e.g., payment processing, PHI) without satisfying additional claim requirements.

**Relevant components**: `ZeroTrustMiddleware`, `ContinuousVerification`, `ClaimSet`, `PolicyEngine`

---

### Tenet 4: Access to resources is determined by dynamic policy

> _"Access is determined by dynamic policy - including the observable state of client identity, application/service, and the requesting asset - and may include other behavioral and environmental attributes."_

**Control coverage in Pulsar**:

- **Multi-signal policy evaluation**: The policy engine evaluates claims produced by five signal provider categories:
- `DeviceSignal` - device registration status, attestation validity, known device recognition
- `LocationSignal` - geographic anomalies, impossible travel detection, country-level location
- `TimeSignal` - off-hours access, unusual time patterns
- `BehaviorSignal` - anomalous request patterns, rapid request detection
- `NetworkSignal` - network zone, Tor exit node detection, known proxy identification
- **Confidence and source gating**: Policy rules specify minimum confidence levels and allowed claim sources. A claim from a low-confidence source (e.g., browser fingerprinting) cannot satisfy a policy requiring high-confidence device attestation. This prevents weak signals from being treated as authoritative.
- **Dynamic step-up decisions**: When claims are insufficient for a resource's policy requirements, the policy engine returns a `step-up` decision, triggering additional authentication factors. This enables adaptive, risk-proportional access control.
- **Configurable policy rules**: `ZeroTrustConfig` DTO supports per-resource policy definitions with explicit claim requirements, confidence thresholds, and allowed sources. Policies are stored as diffable configuration.

**Relevant components**: `PolicyEngine`, `PolicyRule`, `PolicyDecision`, signal providers (`DeviceSignal`, `LocationSignal`, `TimeSignal`, `BehaviorSignal`, `NetworkSignal`), `ZeroTrustConfig`

---

### Tenet 5: The enterprise monitors and measures the integrity and security posture of all owned and associated assets

> _"No asset is inherently trusted. [...] The enterprise monitors the security posture of the asset when evaluating a resource request."_

**Control coverage in Pulsar**:

- **Device identity with cryptographic proof**: The `DeviceIdentity` component tracks registered devices using secure cookies with cryptographic key pairs. WebAuthn attestation provides high-assurance device binding. Browser fingerprinting is treated as a weak supplementary signal only.
- **Trust score as diagnostic metric**: `TrustScore::fromClaims()` computes a real-time aggregate metric from the current claim set. The `ScoreExplanation` object provides full transparency into which claims contributed to the score and by how much. This score serves dashboards, telemetry, and anomaly detection - providing continuous visibility into asset security posture.
- **Signal-based posture assessment**: Each request triggers signal evaluation across all configured providers. Claims reflect the current observable state of the requesting asset (device registration status, network characteristics, behavioral patterns, temporal context).

**Relevant components**: `DeviceIdentity`, `TrustScore`, `ScoreExplanation`, signal providers, `ContinuousVerification`

---

### Tenet 6: All resource authentication and authorization are dynamic and strictly enforced before access is allowed

> _"Acquiring and maintaining access to a resource is a constant cycle of scanning, threats evaluation, adapting, and reauthenticating."_

**Control coverage in Pulsar**:

- **Pre-access enforcement**: `ZeroTrustMiddleware` evaluates policy rules before granting access to any protected resource. No request reaches a protected endpoint without a policy decision.
- **Continuous re-evaluation**: The `ContinuousVerification` component re-evaluates claims during active sessions at entrypoints, not only at initial authentication. A session that was granted access may have that access revoked if claims change (e.g., device becomes unrecognized, anomalous behavior detected).
- **Step-up authentication with loop safety**: When policy requires additional verification, step-up authentication is triggered. The step-up mechanism includes:
- Attempt counter per policy rule (configurable maximum, default 3)
- Cooldown period after maximum attempts are exhausted (default 5 minutes)
- Audit events for each attempt (`StepUpAttempted`) and lockout (`StepUpLockout`)
- Deterministic fallback to deny during lockout (no retry loops)
- **Three-valued decisions**: Every policy evaluation produces one of three outcomes - grant, deny, or step-up. There is no implicit grant; every access decision is explicit and logged.

**Relevant components**: `ZeroTrustMiddleware`, `ContinuousVerification`, `PolicyDecision`, step-up loop protection, Auth module integration

---

### Tenet 7: The enterprise collects as much information as possible about the current state of assets, network infrastructure, and communications and uses it to improve its security posture

> _"An enterprise should collect data about asset security posture [...] and use that data to improve policy creation and enforcement."_

**Control coverage in Pulsar**:

- **Comprehensive audit logging**: All policy decisions are audit-logged with:
- Claim set snapshot (inline for small sets, or content-addressable hash referencing the compliance evidence sink for large sets)
- Trust score with full explanation (per-claim weight contributions)
- Decision outcome (grant, deny, or step-up)
- Correlation ID for request tracing
- **HMAC-chained audit trail**: Audit entries use keyed BLAKE2b chain integrity (per ADR-0008), providing tamper-evident logging suitable for forensic analysis and compliance audits.
- **Signal data for security analytics**: Trust scores, claim patterns, and anomaly signals (geolocation anomalies, impossible travel, behavioral anomalies) generate structured data suitable for security posture analysis and policy refinement.
- **Versioned signal retention policies**: Signal retention policies are versioned and changes emit `SignalRetentionPolicyChanged` events. This provides an audit trail of how data collection and retention practices evolve over time.

**Relevant components**: `AuditLogger`, `AuditEntry`, `TrustScore`, `ScoreExplanation`, signal providers, retention policy management

---

## 2. PSD2 strong customer authentication (SCA) mapping

The revised Payment Services Directive (PSD2) requires Strong Customer Authentication for electronic payment transactions. SCA mandates authentication using at least two of three independent factor categories: knowledge, possession, and inherence.

### Factor category mapping

| SCA Factor Category | Description                                                                     | Pulsar Signal/Claim Support                                                                                                                                                                                                                |
| ------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Knowledge**       | Something the user knows (password, PIN, security question)                     | Handled by the Auth module's primary authentication. Claims such as `IdentityVerified=true` and `MfaCompleted=true` reflect knowledge-factor verification.                                                                                 |
| **Possession**      | Something the user possesses (mobile device, hardware token, registered device) | `DeviceSignal` produces `KnownDevice`, `DeviceRegistered`, and `DeviceAttestationValid` claims. WebAuthn attestation provides cryptographic proof of device possession. Secure cookies with key pairs bind sessions to registered devices. |
| **Inherence**       | Something the user is (biometric, behavioral pattern)                           | `BehaviorSignal` produces `AnomalousPattern` and `RapidRequests` claims reflecting behavioral characteristics. Biometric verification (e.g., WebAuthn with biometric authenticators) integrates through the device attestation flow.       |

### Step-up authentication for SCA

PSD2 requires SCA for payment initiation and certain account access operations. Pulsar's zero-trust policy engine supports this through:

- **Payment-specific policy rules**: Policy rules can require `KnownDevice=true AND GeoAnomaly=false` for payment endpoints, ensuring that transactions originate from recognized devices without geographic anomalies.
- **Dynamic step-up**: When a payment request does not satisfy the required claim set, the policy engine returns a `step-up` decision, triggering additional authentication factors through the Auth module's step-up integration.
- **Confidence gating**: Policy rules can require minimum confidence levels for possession-factor claims (e.g., `DeviceAttestationValid=true` with confidence >= 0.9 from source `DeviceSignal`), preventing weak signals from satisfying SCA requirements.

### Transaction monitoring

PSD2 Article 2 and Regulatory Technical Standards (RTS) require transaction risk analysis. The zero-trust module supports this through:

- **Behavioral anomaly detection**: `BehaviorSignal` detects unusual transaction patterns (rapid requests, anomalous patterns) that may indicate fraud.
- **Geographic and temporal context**: `LocationSignal` and `TimeSignal` provide contextual risk indicators (impossible travel, off-hours access) relevant to transaction risk assessment.
- **Audit trail for regulatory reporting**: All policy decisions related to payment transactions are audit-logged with full claim context, supporting regulatory reporting and dispute resolution.

### SCA exemptions

PSD2 RTS Article 10-18 define SCA exemptions (low-value transactions, trusted beneficiaries, recurring payments, transaction risk analysis). The policy engine's configurable rules support exemption modeling - specific resources or transaction types can have reduced claim requirements while maintaining audit evidence of the exemption rationale.

---

## 3. HIPAA access control mapping

The Health Insurance Portability and Accountability Act (HIPAA) Security Rule (45 CFR Part 164) establishes standards for protecting electronic Protected Health Information (ePHI). The following maps Pulsar's zero-trust controls to relevant HIPAA requirements.

### 164.312(a)(1) - Access Control

> _"Implement technical policies and procedures for electronic information systems that maintain electronic protected health information to allow access only to those persons or software programs that have been granted access rights."_

**Control coverage**:

- **Explicit policy rules for PHI resources**: Policy rules can enforce strict claim requirements for PHI access endpoints, such as: `DeviceAttestationValid=true AND NetworkZone=internal` for PHI access.
- **Minimum necessary access**: Policy rules are defined per resource and action, supporting the HIPAA minimum necessary standard. Access to PHI endpoints requires specific claims that are not required for non-PHI resources.
- **No implicit access**: The three-valued decision model (grant/deny/step-up) ensures that PHI access is never implicitly granted. Every access attempt receives an explicit policy evaluation.

### 164.312(a)(2)(i) - Unique User Identification

**Control coverage**:

- **Identity claims**: The claims model carries identity information (`IdentityVerified`, `MfaCompleted`) tied to authenticated users. Device identity via `DeviceRegistered` and `DeviceAttestationValid` provides additional identification assurance.
- **Correlation IDs**: Each policy decision includes a correlation ID linking the access decision to the authenticated user and session context.

### 164.312(a)(2)(iii) - Automatic Logoff

**Control coverage**:

- **Continuous verification**: Session-level claim re-evaluation supports automatic access revocation when trust posture degrades. Combined with session timeout controls (per the session management module), this supports automatic logoff requirements.

### 164.312(a)(2)(iv) - Encryption and Decryption

**Control coverage**:

- **Signal data encryption**: Sensitive signal data (device identifiers, location data) is handled with privacy controls. Device identifiers are pseudonymized in logs. Signal data is stored only for the configured retention period.
- **Session encryption**: Session data is encrypted using AEAD XChaCha20-Poly1305 via the KeyRing (per ADR-0006), protecting session-bound ePHI at rest and in transit.

### 164.312(b) - Audit Controls

> _"Implement hardware, software, and/or procedural mechanisms that record and examine activity in information systems that contain or use electronic protected health information."_

**Control coverage**:

- **HMAC-chained audit logging**: All zero-trust policy decisions for PHI-access resources are logged to the tamper-evident audit trail (per ADR-0008). Each audit entry includes: decision outcome, claim set snapshot, trust score with explanation, correlation ID, actor, action, resource, and timestamp.
- **Claim set snapshots**: For PHI access decisions, the full claim set at the time of the decision is either stored inline in the audit event or referenced via a content-addressable hash in the compliance evidence sink. This provides forensic-grade evidence of the trust basis for each access decision.
- **Chain verification**: The HMAC chain can be independently verified using only the audit key, supporting audit integrity requirements without external infrastructure.

### 164.312(c)(1) - Integrity Controls

**Control coverage**:

- **Tamper-evident audit trail**: keyed BLAKE2b chain integrity ensures that modification of any audit entry is detectable by re-computing the chain from the deterministic seed (per ADR-0008).
- **Immutable audit entries**: `AuditEntry` is a readonly value object. Once written, entries cannot be modified in memory. The `AuditFileSink` uses `LOCK_EX` for concurrent-safe, append-only writes.

### 164.312(d) - Person or Entity Authentication

**Control coverage**:

- **Multi-factor verification via claims**: Policy rules can require combinations of identity verification claims (`IdentityVerified=true`, `MfaCompleted=true`, `DeviceAttestationValid=true`) before granting access to PHI resources. This supports entity authentication requirements through the claims-based model.

### Example: PHI access policy rule

```
Resource: /api/patients/{id}/records
Required claims:
 - IdentityVerified=true (confidence >= 0.95, source: AuthModule)
 - MfaCompleted=true (confidence >= 0.95, source: AuthModule)
 - DeviceAttestationValid=true (confidence >= 0.9, source: DeviceSignal)
 - NetworkZone=internal (confidence >= 0.8, source: NetworkSignal)
 - GeoAnomaly=false (confidence >= 0.8, source: LocationSignal)
Decision on insufficient claims: step-up
Decision on failed claims: deny
Audit: full claim set snapshot to compliance evidence sink
```

---

## 4. Privacy compliance - GDPR and CCPA considerations

The zero-trust module collects and processes contextual data (device metadata, location indicators, behavioral patterns, network characteristics) as trust signals. This data processing must align with privacy requirements under the General Data Protection Regulation (GDPR), the California Consumer Privacy Act (CCPA), and equivalent frameworks.

### 4.1 Data minimization (GDPR Article 5(1)(c))

> _"Personal data shall be adequate, relevant and limited to what is necessary in relation to the purposes for which they are processed."_

**Control coverage**:

- **Purpose-limited signal collection**: Each signal provider collects only the data necessary for its specific claim production. For example, `LocationSignal` produces geographic claims but does not store raw IP addresses beyond the request lifecycle unless explicitly configured.
- **Claim outcomes over raw data**: Audit logs store claim outcomes (grant/deny/step-up + claim names and values) rather than raw signal data. Raw IP addresses, raw device metadata, and raw behavioral data are not persisted in audit logs.
- **Fingerprint data evaluated and discarded**: When browser fingerprinting is used as a weak supplementary signal, fingerprint data is evaluated during the request and discarded - it is not stored persistently.
- **Configurable signal activation**: `ZeroTrustConfig` allows organizations to enable only the signal providers necessary for their risk profile, avoiding unnecessary data collection.

### 4.2 Storage limitation and retention (GDPR Article 5(1)(e))

> _"Personal data shall be kept in a form which permits identification of data subjects for no longer than is necessary."_

**Control coverage**:

- **Per-signal retention periods**: Signal data retention is configurable per signal type through `ZeroTrustConfig`. Organizations can set retention periods appropriate to their regulatory requirements and risk profile.
- **Versioned retention policies**: Retention policy changes emit `SignalRetentionPolicyChanged` events, providing an audit trail of how data retention practices evolve. This supports accountability requirements.
- **Automatic expiration**: Signal data stored beyond the request lifecycle (e.g., device registration records, behavioral baselines) is subject to configurable retention TTLs.

### 4.3 Pseudonymization (GDPR article 25(1), recital 78)

> _"[T]he controller should [...] implement appropriate technical and organisational measures [...] such as pseudonymisation."_

**Control coverage**:

- **Device identifier pseudonymization**: Device identifiers in audit logs are pseudonymized using hash functions with rotation salt. The original device identifier cannot be recovered from the log entry without the salt.
- **No raw IP in audit logs**: Geolocation claims (country, region) are stored as claim values. Raw IP addresses are not persisted in the audit trail.
- **Correlation via opaque identifiers**: Audit entries use correlation IDs and content-addressable hashes rather than directly identifying information for cross-referencing.

### 4.4 Right to erasure (GDPR article 17)

**Considerations**:

- **Device registration data**: Device identity records (registered devices, key pairs) are associated with user accounts. The framework's signal retention configuration supports defining erasure procedures for device data when a user exercises their right to erasure.
- **Audit trail integrity vs. erasure**: HMAC-chained audit entries present a tension with right-to-erasure requests. Removing individual entries would break chain integrity. Organizations should consider:
- Pseudonymization of actor identifiers in audit logs as the primary privacy control (reducing the need for erasure)
- Legal basis for audit log retention under legitimate interest or legal obligation (GDPR Article 17(3)(b) and (e))
- Retention policies that limit the audit log lifetime to what is legally required
- **Behavioral baselines**: Behavioral signal data used for anomaly detection should be subject to erasure procedures consistent with the configured retention period.

### 4.5 Privacy by design (GDPR article 25)

> _"[T]he controller shall [...] implement appropriate technical and organisational measures [...] which are designed to implement data-protection principles."_

**Control coverage**:

- **Default privacy-preserving configuration**: The framework defaults to minimal data collection. Browser fingerprinting is disabled by default and treated as a weak signal when enabled. Raw signal data is not logged by default.
- **Separation of concerns**: Trust signal evaluation is separated from audit logging. Signals produce typed claims; only claim outcomes (not raw signal inputs) flow to the audit trail.
- **Configurable privacy controls**: Organizations can adjust signal providers, retention periods, pseudonymization settings, and audit detail levels to match their privacy requirements.

### 4.6 CCPA considerations

The California Consumer Privacy Act provides rights including disclosure, deletion, and opt-out of sale. Relevant controls:

- **Categories of personal information collected**: Device identifiers, IP-derived location data, and behavioral patterns fall within CCPA's definition of personal information. The framework's signal provider architecture makes the categories of collected data explicit and configurable.
- **Right to deletion**: The same retention and erasure considerations described for GDPR Article 17 apply to CCPA deletion requests.
- **No sale of personal information**: The zero-trust module processes signal data solely for access control and security purposes. Signal data is not shared with third parties or used for purposes beyond security evaluation.

---

## 5. DORA cyber resilience

The Digital Operational Resilience Act (DORA, Regulation (EU) 2022/2554) establishes requirements for ICT risk management, incident reporting, and operational resilience testing for financial entities.

### Article 6 - ICT Risk Management Framework

> _"Financial entities shall have [...] an ICT risk management framework."_

**Control coverage**:

- **Continuous verification as ongoing monitoring**: The `ContinuousVerification` component re-evaluates trust claims during active sessions. This supports the DORA requirement for ongoing monitoring of ICT risks rather than point-in-time assessment.
- **Configurable risk signals**: Signal providers (behavior, network, location, device, time) produce claims that reflect the current ICT risk posture of each access request. The modular signal architecture allows organizations to extend monitoring as their risk landscape evolves.

### Article 9 - Protection and Prevention

> _"Financial entities shall continuously monitor and control the security and functioning of ICT systems."_

**Control coverage**:

- **Anomaly detection signals**: `BehaviorSignal` detects anomalous patterns and rapid requests. `LocationSignal` detects impossible travel and geographic anomalies. `NetworkSignal` identifies Tor exit nodes and known proxies. These signals support early detection of potentially malicious activity.
- **Automated policy enforcement**: The policy engine automatically enforces access restrictions when anomaly signals are detected, reducing response time between detection and containment.

### Article 10 - Detection

> _"Financial entities shall have in place mechanisms to promptly detect anomalous activities."_

**Control coverage**:

- **Trust score telemetry**: `TrustScore` with `ScoreExplanation` provides real-time visibility into the trust posture of each request. Score degradation patterns can trigger alerts and incident response workflows.
- **Audit event stream**: All policy decisions (particularly denials and step-up triggers) generate structured audit events with full context. These events can feed into incident detection and response systems.
- **Step-up lockout events**: `StepUpLockout` events indicate potential brute-force or account takeover attempts, providing detection signals for security operations.

### Article 17 - ICT-Related Incident Management

**Control coverage**:

- **Forensic-grade audit trail**: HMAC-chained audit logging with claim set snapshots provides tamper-evident forensic evidence for incident investigation. Each audit entry captures the complete decision context (claims, score, explanation, correlation ID).
- **Correlation tracing**: Correlation IDs across policy decisions, step-up events, and lockout events support incident reconstruction and root cause analysis.

---

## Appendix A: component reference

| Component                         | Module Path                                  | Compliance Relevance                                                       |
| --------------------------------- | -------------------------------------------- | -------------------------------------------------------------------------- |
| `ClaimSet` / `Claim`              | `Security/ZeroTrust/Claim/`                  | Core data model for all policy evaluation                                  |
| `ClaimSource`                     | `Security/ZeroTrust/Claim/`                  | Source attribution for confidence gating                                   |
| `DeviceSignal`                    | `Security/ZeroTrust/Signal/`                 | PSD2 possession factor, HIPAA entity authentication                        |
| `LocationSignal`                  | `Security/ZeroTrust/Signal/`                 | PSD2 transaction monitoring, DORA anomaly detection                        |
| `TimeSignal`                      | `Security/ZeroTrust/Signal/`                 | Behavioral context for policy decisions                                    |
| `BehaviorSignal`                  | `Security/ZeroTrust/Signal/`                 | PSD2 transaction monitoring, DORA anomaly detection, GDPR inherence factor |
| `NetworkSignal`                   | `Security/ZeroTrust/Signal/`                 | NIST tenet 2 (network-agnostic trust)                                      |
| `PolicyEngine`                    | `Security/ZeroTrust/Policy/`                 | Central policy evaluation for all regulatory frameworks                    |
| `PolicyRule`                      | `Security/ZeroTrust/Policy/`                 | Per-resource access requirements                                           |
| `PolicyDecision`                  | `Security/ZeroTrust/Policy/`                 | Three-valued decision model (grant/deny/step-up)                           |
| `DeviceIdentity`                  | `Security/ZeroTrust/DeviceIdentity/`         | Device registration, cryptographic proof, WebAuthn                         |
| `ContinuousVerification`          | `Security/ZeroTrust/ContinuousVerification/` | NIST tenet 6, DORA continuous monitoring                                   |
| `TrustScore` / `ScoreExplanation` | `Security/ZeroTrust/`                        | NIST tenet 5/7, DORA detection, diagnostic telemetry                       |
| `ZeroTrustMiddleware`             | `Security/ZeroTrust/`                        | Entrypoint enforcement for all frameworks                                  |
| `ZeroTrustConfig`                 | `Security/ZeroTrust/`                        | Configurable controls, privacy settings, retention periods                 |
| `AuditLogger`                     | `Security/Audit/`                            | HIPAA 164.312(b), NIST tenet 7, DORA Article 17                            |
| `AuditEntry`                      | `Security/Audit/`                            | Tamper-evident logging, forensic evidence                                  |
| `KeyRingInterface`                | `Security/Crypto/`                           | Cryptographic key management for device identity, audit integrity          |

## Appendix B: regulatory cross-reference matrix

| Requirement                 | NIST 800-207   | PSD2 SCA    | HIPAA              | GDPR             | DORA        |
| --------------------------- | -------------- | ----------- | ------------------ | ---------------- | ----------- |
| Claims-based access control | Tenets 1, 3, 4 | Art. 97     | 164.312(a)         | Art. 25          | Art. 9      |
| Multi-factor authentication | Tenet 6        | Art. 97(1)  | 164.312(d)         | -                | Art. 9      |
| Continuous verification     | Tenets 5, 6    | -           | 164.312(a)(2)(iii) | -                | Art. 6, 9   |
| Device identity/attestation | Tenet 5        | RTS Art. 9  | 164.312(d)         | Art. 25          | Art. 9      |
| Anomaly detection           | Tenet 7        | RTS Art. 18 | -                  | -                | Art. 10     |
| Tamper-evident audit trail  | Tenet 7        | -           | 164.312(b), (c)    | Art. 5(2)        | Art. 17     |
| Data minimization           | -              | -           | Min. necessary     | Art. 5(1)(c)     | -           |
| Pseudonymization            | -              | -           | -                  | Art. 25, Rec. 78 | -           |
| Retention management        | -              | -           | 164.530(j)         | Art. 5(1)(e)     | -           |
| Incident detection          | Tenet 7        | -           | 164.308(a)(6)      | Art. 33          | Art. 10, 17 |
