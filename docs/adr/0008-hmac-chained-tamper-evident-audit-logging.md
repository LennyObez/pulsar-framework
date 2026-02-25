# ADR-0008: HMAC-Chained Tamper-Evident Audit Logging

## Status

Accepted

## Context

Regulated domains (banking, healthcare, legal) require audit trails that prove events occurred and were not modified after the fact. Standard application logging (`Logger`) records operational events (errors, debug info) but provides no integrity guarantees - log files can be edited, truncated, or reordered without detection.

Common approaches to tamper-evident logging include:

- **Database audit tables** with triggers - tightly coupled to the database engine, hard to export.
- **External SIEM/WORM storage** - requires dedicated infrastructure and vendor contracts.
- **Blockchain-style hash chains** - proven integrity model, but often over-engineered for single-node audit trails.

Pulsar needs an audit logging mechanism that is self-contained, verifiable without external infrastructure, and suitable for compliance audits.

## Decision

Implement a separate audit logging subsystem with keyed BLAKE2b hash chaining. Audit logging is distinct from general application logging and has its own schema, storage, and integrity model.

### Design

- **Structured entries.** Each `AuditEntry` is a readonly value object with fields: `id`, `event` (enum: authentication, authorization, data_access, configuration_change, system), `outcome` (enum: success, failure, denied), `actor`, `action`, `resource`, `timestamp`, and `metadata`.
- **HMAC chain with deterministic seed.** The chain starts from a seed HMAC computed as `keyed BLAKE2b("PULSAR_AUDIT_SEED", auditKey)`. This is not a zero value - it is a deterministic, key-dependent seed that verification tools can reconstruct from the audit key alone. Each subsequent entry's HMAC is computed over all its fields plus the previous entry's HMAC.
- **Restart continuity.** If the sink implements `ChainableAuditSinkInterface`, the `AuditLogger` reads the last entry's HMAC via `lastHmac()` on construction and resumes the chain from that point. If the sink does not support chaining or the file is empty/corrupt, the chain falls back to the seed HMAC. This ensures the chain is continuous across process restarts.
- **Verification.** `AuditEntry::verify(auditKey)` validates a single entry's HMAC. Walking the chain from the seed detects any tampering - modifying any entry invalidates all subsequent entries.
- **Append-only sink.** `AuditFileSink` writes JSON Lines with `LOCK_EX` for safe concurrent appends. The sink interface (`AuditSinkInterface`) allows alternative backends.
- **Derived audit key.** The HMAC key is derived from the master key via KDF with the `pulsar__audit_hmac` context (see ADR-0006). It is never stored in configuration files.

### Separation from general logging

| Concern   | General logging             | Audit logging                                 |
| --------- | --------------------------- | --------------------------------------------- |
| Purpose   | Operational debugging       | Compliance, forensics                         |
| Schema    | Free-form message + context | Structured (actor, action, resource, outcome) |
| Integrity | None                        | HMAC-chained                                  |
| Retention | Short-term, rotatable       | Long-term, legal requirements                 |
| Access    | Developers                  | Compliance officers, auditors                 |

## Consequences

### Positive

- **Tamper detection.** Any modification to the audit trail is detectable by re-computing the HMAC chain. This satisfies compliance requirements for integrity-protected logging.
- **Self-contained verification.** Audit integrity can be checked with the audit key alone - no external service, database, or blockchain required.
- **Separation of concerns.** Audit events have a dedicated schema and storage path. They are not mixed with debug logs or error reports.
- **Extensible sinks.** The `AuditSinkInterface` allows writing to databases, remote services, or WORM storage without changing the core audit logic.

### Negative

- **Chain fragility.** If the audit file is corrupted (partial write, disk failure), the chain breaks from the corruption point forward. Mitigation: `LOCK_EX` and fsync reduce the risk but cannot eliminate it.
- **Single-node guarantee.** The HMAC chain provides integrity on a single node. Distributed systems with multiple audit writers need additional coordination (not provided by the framework).
- **Key dependency.** If the master key is lost, historical audit entries cannot be verified. The key must be backed up and managed operationally.

### Neutral

- **Performance.** keyed BLAKE2b computation is fast (sub-microsecond per entry). The chain does not add meaningful overhead to audit logging.
