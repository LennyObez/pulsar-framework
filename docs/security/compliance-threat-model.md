# Compliance Event System -- Threat Model

## Assets

| Asset                       | Sensitivity | Description                                                           |
| --------------------------- | ----------- | --------------------------------------------------------------------- |
| Audit chain                 | Critical    | HMAC-chained event log providing tamper-evident regulatory evidence   |
| Pseudonymization lookup     | Critical    | Encrypted mapping between real subject IDs and pseudonyms             |
| Evidence archives           | High        | Exported compliance records with SHA-256 hash manifests               |
| Encryption keys (MasterKey) | Critical    | Master key and derived subkeys for encryption, HMAC, pseudonymization |
| Compliance event stream     | High        | Real-time flow of compliance events with nonce + timestamp            |
| Retention policy state      | Medium      | Policy definitions and purge operation history                        |

## Threat Actors

| Actor               | Capability                                                     | Motivation                                             |
| ------------------- | -------------------------------------------------------------- | ------------------------------------------------------ |
| External attacker   | Network access, stolen credentials, application-level exploits | Data exfiltration, evidence destruction, fraud         |
| Malicious insider   | Authenticated access, elevated privileges, system knowledge    | Cover tracks, mass deanonymization, evidence tampering |
| Compromised service | Valid service credentials, inter-module communication access   | Lateral movement, event injection, replay attacks      |

## Threats and Mitigations (STRIDE)

### Tampering

**T1: Audit chain tampering**

- **Threat**: An attacker modifies audit trail entries to conceal unauthorized actions or fabricate compliance evidence.
- **Mitigation**: The audit chain uses HMAC with BLAKE2b (`sodium_crypto_generichash` with a derived key from sub-key ID `2`). Each entry's hash includes the previous entry's hash, forming a chain. A single modified bit in any entry breaks verification of all subsequent entries. The `EventEnvelope` also carries a `payloadHash` (SHA-256 over canonical `eventType|schemaVersion|sorted-json`) that is computed at dispatch time and can be independently verified.
- **Residual risk**: Low. An attacker with access to the HMAC key (sub-key ID `2`) could recompute the chain. Mitigated by MasterKey isolation (see E1).

**T2: Compliance event payload modification**

- **Threat**: An attacker intercepts and modifies event payloads in transit between dispatch and persistence.
- **Mitigation**: `EventEnvelope.payloadHash` is a SHA-256 hash computed from canonical serialization (recursively key-sorted JSON). Any modification to the payload, event type, or schema version produces a different hash. Consumers verify this hash before processing.
- **Residual risk**: Low. Requires access to the event dispatch pipeline.

### Information Disclosure

**I1: Mass deanonymization via pseudonym lookup**

- **Threat**: An attacker or malicious insider queries the pseudonym lookup table to reverse-map all pseudonyms to real subject identifiers.
- **Mitigation**: Three layers of defense:
  1. **Encrypted salt storage**: The per-subject salt is encrypted at rest via `EncryptorInterface` before being stored. Without the encryption key, the lookup table contains only encrypted blobs.
  2. **Key derivation isolation**: The pseudonymization subkey uses sub-key ID `3` with context `pseudo__`, derived via `sodium_crypto_kdf_derive_from_key`. This is cryptographically isolated from the encryption subkey (ID `1`) and audit HMAC subkey (ID `2`).
  3. **Audit logging on resolve**: Every call to `resolve()` emits a `DataAccess` audit event with action `pseudonym.resolve`, creating a tamper-evident record of who accessed the reverse mapping and when.
- **Residual risk**: Medium. A privileged insider with access to both the MasterKey and the lookup store could perform deanonymization. The audit trail would record this activity.

**I2: Log-based data leakage**

- **Threat**: Sensitive data (PAN, PHI, personal identifiers) leaks through application logs.
- **Mitigation**: The `ComplianceLogSink` applies regulation-specific formatters before any log entry reaches the underlying sink:
  - `PciDssLogFormatter`: Irreversible PAN masking (Luhn-validated, last-4-only), CVV/expiry full masking
  - `GdprLogFormatter`: SHA-256 pseudonymization of personal data fields
  - `HipaaLogFormatter`: PHI detection with `phi_access` flagging, patient ID pseudonymization
  - Optional full-entry encryption via `EncryptorInterface`
- **Residual risk**: Low. Custom log sinks that bypass `ComplianceLogSink` would not benefit from these protections.

### Repudiation

**R1: Evidence archive forgery**

- **Threat**: An attacker creates or modifies evidence archives to present fabricated compliance evidence during an audit.
- **Mitigation**:
  - Every `EvidenceExportResult` includes a `hashManifest` (SHA-256 of JSON-serialized records).
  - The `operatorIdentity` is recorded on every export operation, linking the archive to a specific human operator.
  - The export operation itself is recorded in the tamper-evident audit chain.
- **Residual risk**: Low. An attacker who controls both the archive and the hash storage could forge consistent pairs. Mitigated by storing hashes in the audit chain.

**R2: Pseudonym deletion without accountability**

- **Threat**: An insider deletes pseudonym mappings (right-to-forget) without proper authorization, then denies the action.
- **Mitigation**: `ForgetService::forget()` emits a `DataModification` audit event with the subject ID and pseudonym before returning. The `ForgetResult` includes the `auditEntryId` linking the erasure to the audit chain. The audit chain is HMAC-protected, preventing after-the-fact modification of these records.
- **Residual risk**: Low.

### Denial of Service

**D1: Replay attacks on compliance events**

- **Threat**: A compromised service replays previously captured compliance events to pollute the audit trail, trigger duplicate processing, or exhaust storage.
- **Mitigation**: Every `ComplianceEvent` carries a cryptographic `nonce` (16 bytes from `Randomizer(Secure)`) and a `DateTimeImmutable` timestamp. Consumers can detect and reject duplicates by tracking seen nonces within a time window. The `EventEnvelope` adds its own `eventId` for additional deduplication.
- **Residual risk**: Low. Requires consumers to implement nonce tracking. The framework provides the nonce; enforcement is deployment-specific.

**D2: Retention purge abuse**

- **Threat**: An attacker triggers premature purges to destroy compliance evidence before the retention period expires.
- **Mitigation**: `RetentionManager::purge()` calculates the cutoff date from the retention policy and only purges records older than the retention period. Every non-dry-run purge emits a `DataModification` audit event with the operator identity, policy details, and affected date range. The dry-run mode allows previewing purge scope without executing.
- **Residual risk**: Low. A privileged insider who modifies the retention policy and then runs a purge could reduce retention periods. Mitigated by policy versioning and audit trail.

### Elevation of Privilege

**E1: Master key compromise**

- **Threat**: An attacker obtains the application master key, gaining the ability to derive all subkeys (encryption, audit HMAC, pseudonymization).
- **Mitigation**:
  - **KDF isolation**: Each purpose uses a distinct sub-key ID and 8-byte context, so compromise of one derived subkey does not reveal the master key or other subkeys.
  - **Memory zeroing**: `MasterKey::__destruct()` calls `sodium_memzero()` on key material when the object is garbage collected.
  - **Serialization prevention**: `MasterKey::__serialize()` and `__unserialize()` throw `SecurityException`, preventing key material from being serialized to caches, sessions, or logs.
  - **Debug protection**: `MasterKey::__debugInfo()` returns `[REDACTED]` instead of key material.
  - **Key rotation support**: `MasterKey` supports a previous key for rotation windows via `PULSAR_MASTER_KEY_PREVIOUS`, enabling seamless rotation without service disruption.
- **Residual risk**: Medium. The master key is loaded from the `PULSAR_MASTER_KEY` environment variable. Environment variable access controls are deployment-specific.

**E2: Authorization bypass for compliance operations**

- **Threat**: An attacker bypasses authorization to emit forged compliance events or access pseudonymization services.
- **Mitigation**: Authorization events (`AuthorizationGranted`, `AuthorizationDenied`, `PrivilegeEscalated`, `StepUpAuthRequired`) are dispatched by the Gate and carry their own nonce and correlation ID. Step-up authentication can be required for sensitive operations based on trust score evaluation.
- **Residual risk**: Low. Authorization enforcement depends on correct Gate configuration at the application level.

## Abuse Cases

### AC1: Malicious admin attempting mass deanonymization

**Scenario**: A database administrator with access to the pseudonym lookup table attempts to reverse-map all pseudonyms to real subject identifiers.

**Mitigations**:

- Every call to `PseudonymizationService::resolve()` emits a `DataAccess` audit event. A bulk resolution campaign would produce a conspicuous burst of audit entries.
- The per-subject salt is encrypted at rest. Direct database queries return encrypted blobs, not usable salts.
- The pseudonymization subkey (ID `3`) must be derived from the MasterKey, which is not stored in the database.

**Detection**: Monitor audit trail for anomalous volume of `pseudonym.resolve` events.

### AC2: Attacker modifying audit trail

**Scenario**: An attacker with database write access modifies audit entries to remove evidence of unauthorized PHI access.

**Mitigations**:

- The audit chain uses HMAC with BLAKE2b. Each entry's hash incorporates the previous entry's hash. Modifying a single entry invalidates the hash chain from that point forward.
- `AuditTrailVerified` events (SOX) record periodic integrity checks. A broken chain would be detected at the next verification.
- The `EventEnvelope.payloadHash` provides an independent integrity check on the original event payload.

**Detection**: Run periodic audit trail verification. Any hash chain break indicates tampering.

### AC3: Insider accessing evidence without authorization

**Scenario**: An employee exports compliance evidence archives for personal use or to leak regulated data.

**Mitigations**:

- `EvidenceExporterInterface::export()` requires an `operatorIdentity` parameter, which is recorded in the `EvidenceExportResult` and the audit trail.
- The export operation emits audit events that link the archive to the operator.
- Production implementations should require authentication before invoking the exporter.

**Detection**: Monitor audit trail for `evidence.export` events from unexpected operator identities.

### AC4: Replay attack on compliance events

**Scenario**: A compromised service captures valid compliance events and replays them to create duplicate consent records or PHI access logs.

**Mitigations**:

- Every `ComplianceEvent` carries a unique `nonce` (128-bit random) and `occurredAt` timestamp.
- The `EventEnvelope` carries its own unique `eventId` (128-bit random).
- Consumers can implement nonce/eventId deduplication with a time-windowed seen-set.

**Detection**: Duplicate nonce or eventId values within a time window indicate replay.

## Residual Risks

| ID   | Risk                                          | Severity | Rationale                                                                                                               |
| ---- | --------------------------------------------- | -------- | ----------------------------------------------------------------------------------------------------------------------- |
| RR-1 | Environment variable key exposure             | Medium   | MasterKey loaded from `PULSAR_MASTER_KEY` env var; access control is deployment-specific                                |
| RR-2 | Privileged insider deanonymization            | Medium   | An insider with MasterKey access and database access can reverse pseudonyms; audit trail records the activity           |
| RR-3 | Nonce deduplication not enforced by framework | Low      | Framework provides nonces on every event; consumers must implement deduplication                                        |
| RR-4 | Custom log sinks bypassing formatters         | Low      | Log entries written through sinks other than `ComplianceLogSink` are not masked                                         |
| RR-5 | Retention policy manipulation                 | Low      | Versioned policies and audit trail provide accountability, but a privileged admin could create a short-retention policy |

## Security Recommendations

### Key Management

- Store `PULSAR_MASTER_KEY` in a secrets manager (AWS Secrets Manager, HashiCorp Vault, Azure Key Vault) rather than plain environment variables.
- Rotate the master key periodically using the `PULSAR_MASTER_KEY_PREVIOUS` mechanism for zero-downtime rotation.
- Restrict access to the MasterKey environment variable to the minimum set of processes that require it.

### Pseudonymization

- Implement rate limiting on `PseudonymizationService::resolve()` to detect and prevent bulk deanonymization attempts.
- Alert on anomalous volume of `pseudonym.resolve` audit events.
- Consider separating the pseudonym lookup store from the primary database with independent access controls.

### Audit Chain

- Run `AuditTrailVerified` checks on a scheduled basis (at minimum daily for SOX-controlled environments).
- Export audit chain segments to immutable storage (write-once, read-many) for long-term preservation.
- Implement real-time alerting on audit chain verification failures.

### Evidence Archives

- Use an `EvidenceExporterInterface` implementation that encrypts archives at rest and requires multi-party authorization for export.
- Store hash manifests in a separate, tamper-evident store from the archives themselves.
- Verify hash manifests before accepting archives during regulatory audits.

### Event Stream

- Implement nonce deduplication at the event consumer level with a time-windowed seen-set (recommended window: 2x the maximum expected event delivery latency).
- Use TLS for all inter-service event transport.
- Validate `EventEnvelope.payloadHash` at every consumer before processing.

### Log Sink Configuration

- Route all application log sinks through `ComplianceLogSink` to apply regulation-specific formatting.
- Enable the `EncryptorInterface` on `ComplianceLogSink` in production to encrypt log entries at rest.
- Audit log sink configuration as part of deployment validation to prevent unformatted sinks from leaking sensitive data.
