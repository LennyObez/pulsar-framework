# Compliance matrix

Pulsar is designed for regulated, mission-critical domains. This document describes what the **framework offers**. It does not describe what your deployment **achieves** — nothing written by hand can, because almost every control below depends on configuration, on bindings, and on obligations discharged outside the software.

For what a deployment achieves, run:

```bash
php bin/pulsar compliance:report
```

That command assesses the running deployment control by control, computing each outcome from a probe rather than from a stored answer, and exits non-zero when a control an enabled framework claims is not observed. Its output is the artefact to hand an assessor. This page is background reading for it.

> **Why the framework-by-framework grid is gone.** Earlier revisions of this page carried a matrix of about 550 hand-maintained cells, one per capability per framework, each a letter typed by a human and verified by nobody. That is the same defect [ADR-0045](adr/0045-a-control-status-is-observed-not-written.md) removed from the code — a status written as a literal — expressed in Markdown instead of PHP. The grid claimed `S`, "fully addressed with secure defaults", for encryption at rest across all ten frameworks, when the encryptor is registered only inside a conditional branch that both shipped environment templates leave unentered. The per-capability status below is one claim per row instead of eleven, each one checkable against a named file, and the per-framework detail now lives where it can be computed.

## Legend

| Symbol | Name            | Meaning                                                                                                                                            |
| ------ | --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `S`    | Supported       | Active in a default deployment. No operator action beyond copying a template.                                                                      |
| `K`    | Key-conditional | Complete, but registered **only** when `PULSAR_MASTER_KEY` resolves. Both shipped templates ship it empty, so it is **inactive until you set it**. |
| `B`    | Bound but inert | An implementation ships and resolves, but the default one does not survive a restart (in-memory). It satisfies the type, not the requirement.      |
| `I`    | Interface only  | Pulsar defines the contract and ships no implementation you can deploy.                                                                            |
| `O`    | Optional        | Present, but off or incomplete until the application configures or extends it.                                                                     |
| `M`    | Manual          | Architectural support only; the implementation is application-scoped.                                                                              |
| `H`    | Host obligation | Discharged outside the software — policy, personnel, physical, contractual, infrastructure. **No framework code can satisfy it.**                  |
| `X`    | Not present     | Named in earlier revisions of this document, but no such capability exists in the tree.                                                            |

`S` is deliberately narrow. A capability that needs a key, a binding, a subclass or a signature is not "supported with secure defaults" — it is supported once you have done something, and the row says which thing.

`H` is the distinction this document previously blurred and that a reader most needs. A framework can log an access decision; it cannot approve a policy, train a person, lock a door or sign a processor agreement. Rows marked `H` are not gaps in Pulsar. They are work that is yours no matter which framework you choose, and an assessor will ask you for the artefact, not for the code.

## The one condition that governs most of this page

`PULSAR_MASTER_KEY` is the root secret from which every at-rest protection is derived. `SecurityWiring` registers all of them inside a single conditional on that value. With no key — the state both `.env.example` and `.env.production.example` ship in — the branch never runs, and:

- no `EncryptorInterface` is bound, so nothing encrypts application data at rest;
- no `SessionEncryption` is bound, so session payloads are stored unencrypted, while `config/security.php` still reads `'encryption' => true`;
- the audit HMAC chain is never keyed, so the audit trail is not tamper-evident;
- no `SecretVault` and no tokenization service exist, so PAN tokenization is unavailable.

Nothing throws. The application boots and serves traffic. A key that is present but malformed is caught and discarded during wiring, producing the same state just as quietly.

Two gates exist for this. `composer deploy:check` refuses staging and production without a usable key — the `master-key` gate is error-severity, and any error-severity result exits the command non-zero, so a pipeline reading the exit code stops. `pulsar compliance:report` reports each dependent control as unsatisfied. Neither runs in local development, which is why the templates carry the warning inline.

## Capability status

Each row names the capability, its status, and the specific thing an operator must do to move it. The status is about the capability in a default deployment; it is never a claim about a framework's requirement being met, which only an assessment of your deployment can speak to.

### 1. Cryptography and key management

| Requirement area              | Pulsar capability                                                | Status | What the operator must do                                                                               |
| ----------------------------- | ---------------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------- |
| Approved algorithms only      | libsodium-only policy; no external crypto packages (ADR-0006)    | `S`    | Nothing. The policy is enforced in the tree, and `cryptographic_capability` is measured at report time. |
| Password hashing              | Argon2id via PHP native `password_hash` [^1]                     | `S`    | Nothing.                                                                                                |
| HMAC / message authentication | keyed BLAKE2b via `HmacInterface`                                | `S`    | Nothing for the primitive — `HmacService` binds unconditionally. Keying the **audit chain** is `K`.     |
| Encryption at rest            | `EncryptorInterface` (XSalsa20-Poly1305 via libsodium)           | `K`    | Set `PULSAR_MASTER_KEY`. Without it there is no encryptor at all.                                       |
| Key derivation                | Single master key with KDF domain separation (ADR-0006)          | `K`    | Set `PULSAR_MASTER_KEY`.                                                                                |
| Key rotation                  | `KeyRingInterface` with key ID (`kid`) tracking                  | `K`    | Set `PULSAR_MASTER_KEY`; keep `PULSAR_MASTER_KEY_PREVIOUS` for the rotation window.                     |
| PAN tokenization              | `TokenizationServiceInterface` + `DatabaseTokenStore` (ADR-0041) | `K`    | Set the key **and** configure a database connection, or the vault resolves to the in-memory store.      |
| Encryption in transit         | —                                                                | `H`    | Terminate TLS at your web server, proxy or load balancer. Pulsar has no TLS listener.                   |

[^1]: Password hashing uses PHP's native `password_hash($password, PASSWORD_ARGON2ID)` rather than `sodium_crypto_pwhash_str()`. This is a deliberate exception to the libsodium-only policy (ADR-0006): PHP's built-in Argon2id provides equivalent security with better upgrade ergonomics via `password_needs_rehash()`.

**Correction.** Earlier revisions listed "Encryption in transit" against the capability "Secure cookie flags (`Secure`, `HttpOnly`, `SameSite=Strict`)". Cookie flags instruct a browser; they do not encrypt a transport. Pulsar can enforce HSTS and refuse to emit a cookie without `Secure`, and does — but the encryption itself is the host's, and marking it `O` implied the framework carried part of it.

### 2. Authentication and access control

| Requirement area               | Pulsar capability                                       | Status | What the operator must do                                                                                                      |
| ------------------------------ | ------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------------------------ |
| Session management             | `SessionInterface`, regeneration on privilege change    | `S`    | Nothing. `HttpOnly` and `SameSite=Strict` are fixed in `config/security.php`; `Secure` defaults on outside local.              |
| CSRF protection                | `CsrfMiddleware`, synchronizer token pattern (ADR-0035) | `S`    | Nothing.                                                                                                                       |
| Role-based authorization       | Roles and permissions model                             | `S`    | Define your roles and permissions. The mechanism is complete; the policy is yours.                                             |
| Multi-factor authentication    | `TwoFactorManagerInterface` with TOTP                   | `S`    | Enrol users. `MfaScopeRank` derives the required scope from the enabled frameworks.                                            |
| Second-factor rate limiting    | `TwoFactorRateLimiterInterface`                         | `I`    | Bind an implementation. Nothing answers this interface by default, so second-factor guessing is unthrottled.                   |
| Rate limiting                  | `RateLimitConfig` with configurable windows             | `O`    | Configure windows for your endpoints; bind a cache so counters survive a process.                                              |
| Session payload encryption     | `SessionEncryption`                                     | `K`    | Set `PULSAR_MASTER_KEY`.                                                                                                       |
| Strong customer authentication | Guard system (`session`, `token`) + 2FA                 | `M`    | PSD2 SCA is an assembly of dynamic linking, transaction risk analysis and exemptions. Pulsar supplies factors, not the scheme. |
| Route data classification      | Route-level classification metadata                     | `O`    | Declare a classification on routes handling regulated data. None of the default routes does, and the report says so.           |

### 3. Audit logging and accountability

| Requirement area        | Pulsar capability                                             | Status | What the operator must do                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| ----------------------- | ------------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Structured audit events | `AuditEvent` enum, `AuditEntry` with actor/action/resource    | `S`    | Nothing for the vocabulary. Emitting an event for **your** domain actions is application work.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| Audit persistence       | `AuditSinkInterface` → `AuditFileSink`                        | `S`    | Ensure the sink path is writable and included in your backup and retention regime.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| Immutable audit trail   | HMAC chain over every field plus the previous HMAC (ADR-0008) | `K`    | Set `PULSAR_MASTER_KEY`. Without it entries are written unchained and tamper-evidence is a claim with no key behind it.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| Chain verification      | `AuditChainVerifier`                                          | `K`    | Set the key, then run verification on a schedule and retain the result.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| Evidence chain          | `EvidenceChain` over `FileEvidenceStore`                      | `K`    | Set `PULSAR_MASTER_KEY` and run `scheduler:tick`. The chain is built at boot over an append-only file beside the audit trail; without the key there is no chain and the boot log says so. Records are collected on `verification.evidence_interval`, so the trail needs the scheduler enabled. Keep the register **and its `.head` anchor** in your backup regime — the anchor is what makes records removed from the end of the chain detectable, and one restored without the other reads as tampering. Read "[When the evidence register cannot be verified](#when-the-evidence-register-cannot-be-verified)" before rotating the master key. |
| Audit log retention     | `RetentionPolicyInterface` + profile-derived periods          | `O`    | The resolved profile computes a period (2190 days across the default framework set); enforcing it needs a bound purge.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| Actor attribution       | Actor, action, resource on every entry                        | `S`    | Populate the actor from your authentication context.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| Log review              | —                                                             | `H`    | Someone must read the trail and record that they did. No framework can discharge this.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |

#### When the evidence register cannot be verified

The evidence chain verifies the stored register before it continues from it, through the same code path `verify()` uses, so the resumer and the verifier cannot reach two different opinions about one file. It checks that the medium is fully readable, that the register's height matches what its **anchor** attests, that the positions signed into the records are the positions the chain wrote, and that the last record authenticates under the key in service. Anything short of intact and the chain **refuses to append**, and `pulsar scheduler:tick` logs `UnverifiableEvidenceChainException` on every interval, naming what was found. Verification itself still runs; only the evidence record is refused.

The anchor is the file named `<register>.head`. It carries a signed statement of how many records the chain has written, and it exists because a hash chain cannot see records deleted from its end: the survivors of a truncation still chain to one another and still verify. **Archive the register and its anchor together, and back them up together.** A register restored without its anchor reports `Unreadable`; an anchor restored without its register reports `Truncated`.

One case is not a refusal: a register one record taller than its anchor is what a crash between the record write and the anchor write leaves. Only the key holder could have produced that record, so the chain reports it intact and re-anchors on the next run.

The refusal is deliberate, and it is not a judgement about what happened. A master-key rotation, a register restored from another deployment's backup and a genuine tamper are indistinguishable from inside the process: whoever wrote the file wrote every field in it, so no field in it can settle the question. What the framework will not do is guess — appending anyway would chain a genuine record onto an unauthenticated one and every later record would verify, burying the discontinuity mid-file; restarting from genesis would produce a short, perfectly valid chain that says nothing about the records it replaced. Either way an auditor is handed a chain that verifies over a period in which the evidence did not.

Two recoveries, both operator acts:

- **Archive the register.** Move the existing file **and its `.head` anchor** aside together (both are evidence in their own right — keep them, and record why they were archived). The chain starts again from genesis over an empty store on the next interval.
- **Restore the key.** If the register is intact and the key changed — a rotation, or a restore onto a host with a different `PULSAR_MASTER_KEY` — putting the original key back in service resumes the existing chain with no gap.

Rotating `PULSAR_MASTER_KEY` therefore closes the current evidence chain. Archive the register as part of the rotation, the same way the audit trail is rotated, so the period before the rotation stays verifiable under the key it was signed with.

### 4. Data protection and privacy

| Requirement area            | Pulsar capability                                                                         | Status | What the operator must do                                                                                                                                                                                                      |
| --------------------------- | ----------------------------------------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Sensitive parameter masking | `#[SensitiveParameter]` on key material                                                   | `S`    | Nothing. Applies it to Pulsar's own crypto surfaces; apply it to yours.                                                                                                                                                        |
| Retention policy            | `RetentionPolicyInterface`, `DefaultRetentionPolicy`                                      | `O`    | Bind a policy. Nothing answers the interface by default, so no schedule is in force.                                                                                                                                           |
| Automated data purging      | `DataPurgeInterface` → `DataPurgeOrchestrator`, `data:purge`, `data-protection:purge` job | `S`    | Register purges for **your** domain data. The orchestrator covers audit logs and sessions, runs on `purge.schedule` (default 03:00 daily) once the scheduler is enabled, and runs on demand via `pulsar data:purge --dry-run`. |
| Consent management          | `ConsentManagerInterface` → `InMemoryConsentManager`                                      | `B`    | **Replace the default binding.** The in-memory manager loses every consent record on restart, which for GDPR Art. 7 is worse than an unbound interface: it satisfies the type and answers nothing.                             |
| Consent records             | `ConsentRecordInterface` with subject, purpose, version                                   | `I`    | Implement a durable record store.                                                                                                                                                                                              |
| Right to erasure            | `DataPurgeInterface` targeted purging                                                     | `O`    | Bind a purge that reaches your domain data. Erasure across your schema is application work.                                                                                                                                    |
| Subject access requests     | `DsarStoreInterface`                                                                      | `I`    | Implement it. There is no implementation in the tree, so GDPR Art. 15 and CCPA 1798.100 cannot be observed.                                                                                                                    |
| Pseudonymisation            | `PseudonymizationServiceInterface` → `PseudonymizationService` over `FilePseudonymLookup` | `B`    | Nothing, with a master key set. Decide where the mapping table lives: it is the re-identification table, it defaults to sitting beside the audit trail, and GDPR Art. 4(5) wants it kept separately.                           |
| Data minimisation           | Explicit scoping via retention and classification                                         | `M`    | A design property of your schema. The framework can express it; it cannot decide it.                                                                                                                                           |
| Lawful basis, DPIA, DPO     | —                                                                                         | `H`    | Appoint, assess, document, and agree processor terms.                                                                                                                                                                          |

### 5. Incident management

| Requirement area           | Pulsar capability                                     | Status | What the operator must do                                                                                                                                                                                                                           |
| -------------------------- | ----------------------------------------------------- | ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Severity classification    | `IncidentSeverity` enum                               | `S`    | Nothing.                                                                                                                                                                                                                                            |
| Incident recording         | `IncidentReporterInterface` → `FileIncidentReporter`  | `B`    | Nothing, while audit logging is on: the register is written beside the audit trail and survives a restart. With `observability.audit.enabled` off it falls back to the in-memory register, which evidences nothing after the request that wrote it. |
| Incident records           | `IncidentInterface` with structured metadata          | `S`    | Populate it.                                                                                                                                                                                                                                        |
| Breach notification timing | Profile-derived deadline (24h across the default set) | `O`    | The deadline is computed from your enabled frameworks. Meeting it is a procedure, not a setting.                                                                                                                                                    |
| Regulator notification     | —                                                     | `H`    | Notify the supervisory authority. Pulsar captures detail; it does not file.                                                                                                                                                                         |
| Incident response plan     | —                                                     | `H`    | Write it, rehearse it, and keep the evidence of both.                                                                                                                                                                                               |

### 6. Observability and monitoring

| Requirement area      | Pulsar capability                                 | Status | What the operator must do                                                                                          |
| --------------------- | ------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------------ |
| Structured logging    | First-party structured logging (PSR-3 compatible) | `S`    | Nothing.                                                                                                           |
| Metrics collection    | `MetricRegistry`                                  | `O`    | Bind an exporter and a backend to scrape it.                                                                       |
| Distributed tracing   | Spans, Fiber-scoped context propagation           | `O`    | Bind a `SpanProcessorInterface`. Nothing answers it by default, so no span leaves the process.                     |
| Error tracking        | First-party error grouping                        | `O`    | Route it somewhere durable.                                                                                        |
| Health checks         | Health check runner                               | `O`    | Wire a runner and register checks. With none wired, nothing in the deployment is monitored and the report says so. |
| Continuous monitoring | —                                                 | `H`    | Someone must watch the output and act. The alert path, the rota and the escalation are yours.                      |

### 7. Resilience and availability

| Requirement area             | Pulsar capability                          | Status | What the operator must do                                                                                                                                                                                                                                   |
| ---------------------------- | ------------------------------------------ | ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Circuit breaker              | Resilience module, configurable            | `S`    | Configure thresholds per dependency.                                                                                                                                                                                                                        |
| Retry with backoff           | Resilience module retry policies           | `S`    | Choose policies per operation.                                                                                                                                                                                                                              |
| Idempotency                  | `Idempotency` module for safe replays      | `S`    | Apply it to the endpoints that need it.                                                                                                                                                                                                                     |
| Queue and worker supervision | `Queue`, `Supervisor` with signal handling | `S`    | Run the supervisor under a process manager.                                                                                                                                                                                                                 |
| **Backup and restore**       | —                                          | `X`    | **There is no backup or restore primitive in `src/`.** Provide one at the infrastructure layer. This is the residual gap behind NIST CSF RC.RP, SOC 2 A1.3 and HIPAA §164.308(a)(7); the report reports it unsatisfied and cannot be made to say otherwise. |
| Disaster recovery testing    | —                                          | `H`    | Rehearse a restore, time it, and keep the record.                                                                                                                                                                                                           |

**Correction.** Earlier revisions answered SOC 2 "A1.2 (Availability — recovery)" with "Resilience module (circuit breaker, retry), queue/supervisor for job processing". A circuit breaker sheds load from a failing dependency; it does not recover data. Presenting it against a recovery criterion is precisely the substitution ADR-0041 recorded, and the `pulsar/cms` extension's `BackupServiceInterface` covers CMS content, not the framework.

### 8. Configuration and deployment

| Requirement area             | Pulsar capability                                          | Status | What the operator must do                                                                                                                |
| ---------------------------- | ---------------------------------------------------------- | ------ | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Typed configuration          | Readonly config DTOs with `fromArray()` factories          | `S`    | Nothing.                                                                                                                                 |
| Unknown-key detection        | `ReportsUnknownKeys` (ADR-0036)                            | `S`    | Nothing. A typo'd key is reported rather than ignored.                                                                                   |
| Environment-based overrides  | `Environment` with per-environment resolution              | `S`    | Nothing.                                                                                                                                 |
| Deploy severity gates        | `Deploy` module; `debug-mode` and `master-key` fail closed | `S`    | Run `composer deploy:check` in your pipeline. It is not run for you.                                                                     |
| Deployment integrity         | `Integrity` module for artifact verification               | `O`    | Generate and sign a manifest on a host that has the master key; enable `PULSAR_VERIFY_ARTIFACTS`.                                        |
| Feature flags                | `FeatureFlag` module                                       | `O`    | Off by default; enable and define flags.                                                                                                 |
| Secure development lifecycle | —                                                          | `H`    | The gates in this repository govern **Pulsar's** development, not yours. An assessor will ask for a CI run against your deployed commit. |

### 9. Multi-tenancy and isolation

| Requirement area | Pulsar capability                        | Status | What the operator must do                                                                             |
| ---------------- | ---------------------------------------- | ------ | ----------------------------------------------------------------------------------------------------- |
| Tenant isolation | `Tenancy` module with per-tenant context | `O`    | Off by default (`TENANCY_ENABLED=false`). Enable it and scope your queries.                           |
| Data segregation | Tenant-scoped database and storage       | `O`    | Segregation holds for what you route through the tenant context. It is not automatic for raw queries. |

### 10. Extensibility and modularity

| Requirement area            | Pulsar capability                                          | Status | What the operator must do                                                  |
| --------------------------- | ---------------------------------------------------------- | ------ | -------------------------------------------------------------------------- |
| Module boundary enforcement | Deptrac + `#[Api]`/`#[Internal]` checks (ADR-0002/0009)    | `S`    | Nothing for Pulsar's own boundaries; run the checks over your modules too. |
| Extension lifecycle         | Six-phase lifecycle via `pulsar.json` manifests (ADR-0004) | `S`    | Nothing.                                                                   |
| Extension trust tiers       | Trust tiers for third-party extensions (ADR-0023)          | `O`    | Decide which tiers you accept.                                             |
| API stability tracking      | `#[Api(since)]` + public API snapshot                      | `S`    | Nothing for Pulsar's surface. Your own API's stability is yours to track.  |

## Frameworks

Nineteen frameworks have mappings. What a mapping declares is not what a deployment satisfies — `pulsar compliance:report` is the only thing that can tell you the latter. The shipped `enabled_frameworks` is `[Gdpr]` alone, and the reason is [ADR-0046](adr/0046-a-claim-is-something-the-installation-delivers.md): a deployment is held to what it enables. Two of GDPR's four probed controls are observed on a default installation and two are not — [ADR-0050](adr/0050-a-fact-is-produced-only-by-the-component-that-measures.md) withdrew resolved identity as proof, and Art. 25 and Art. 33 had rested on it — so `composer compliance:check` fails there until an observer exercises the pseudonymisation service and the incident register. The failure is left standing rather than configured away; `config/compliance.php` says exactly which two controls and what would close them. `config/compliance.php` lists, framework by framework, what a default installation does not deliver, so enabling one holds no surprises.

| Framework    | Full name                                                   | Mapping                                         |
| ------------ | ----------------------------------------------------------- | ----------------------------------------------- |
| SOC 2        | AICPA Trust Services Criteria                               | `src/Compliance/Frameworks/Soc2Mapping.php`     |
| HIPAA        | HIPAA Security Rule (incl. 2026 NPRM)                       | `src/Compliance/Frameworks/HipaaMapping.php`    |
| GDPR         | EU General Data Protection Regulation (2016/679)            | `src/Compliance/Frameworks/GdprMapping.php`     |
| PCI DSS      | Payment Card Industry Data Security Standard v4.0.1         | `src/Compliance/Frameworks/PciDssMapping.php`   |
| NIS2         | Network and Information Security Directive 2 (EU 2022/2555) | `src/Compliance/Frameworks/Nis2Mapping.php`     |
| ISO 27001    | Information Security Management Systems (Annex A)           | `src/Compliance/Frameworks/Iso27001Mapping.php` |
| ISO 42001    | AI Management Systems (ISO/IEC 42001:2023)                  | `src/Compliance/Frameworks/Iso42001Mapping.php` |
| **NIST CSF** | **NIST Cybersecurity Framework 2.0**                        | `src/Compliance/Frameworks/NistCsfMapping.php`  |
| PSD2         | Payment Services Directive 2 (EU 2015/2366)                 | `src/Compliance/Frameworks/Psd2Mapping.php`     |
| eIDAS        | Electronic Identification and Trust Services (EU 910/2014)  | `src/Compliance/Frameworks/EidasMapping.php`    |
| MDR          | Medical Device Regulation (EU 2017/745)                     | `src/Compliance/Frameworks/MdrMapping.php`      |
| HL7/FHIR     | Health Level Seven / FHIR                                   | `src/Compliance/Frameworks/Hl7FhirMapping.php`  |
| ISO 13485    | Quality Management Systems for Medical Devices              | `src/Compliance/Frameworks/Iso13485Mapping.php` |
| DORA         | Digital Operational Resilience Act (EU 2022/2554)           | `src/Compliance/Frameworks/DoraMapping.php`     |
| SWIFT CSP    | SWIFT Customer Security Programme                           | `src/Compliance/Frameworks/SwiftCspMapping.php` |
| CCPA         | California Consumer Privacy Act                             | `src/Compliance/Frameworks/CcpaMapping.php`     |
| DSA          | EU Digital Services Act                                     | `extensions/compliance/dsa/`                    |
| Data Act     | EU Data Act                                                 | `extensions/compliance/data-act/`               |
| **AI Act**   | **EU Artificial Intelligence Act (EU 2024/1689)**           | `src/Compliance/Frameworks/AiActMapping.php`    |

Enable the ones you must satisfy in `config/compliance.php`. Enabling a framework **is** the claim that the deployment must satisfy it: there is no configuration key that scopes a whole standard out, and the way to stop being assessed against one is to stop enabling it, in a diff.

### NIST CSF 2.0

NIST CSF was absent from every earlier revision of this page while its mapping published ten outcomes, several of them as `Implemented`. It is listed here now, with the gap that governs it stated first.

- **GV (Govern)** — `H`. Organizational context, risk strategy, roles, and oversight. Framework code has no part in it.
- **ID (Identify)** — `O`/`H`. Asset and route classification metadata exists and is unpopulated by default; the asset inventory itself is yours.
- **PR (Protect)** — `K` for the cryptographic and session protections; `S` for access control and CSRF. This is the function Pulsar covers best.
- **DE (Detect)** — `O`. Structured logging, metrics and tracing exist; the detection logic, thresholds and alerting are the deployment's.
- **RS (Respond)** — `B`/`H`. Incident recording resolves to an in-memory reporter by default; response itself is procedural.
- **RC (Recover)** — `X`. **There is no backup or restore primitive in the framework.** RC.RP cannot be satisfied by any deployment of Pulsar as the tree stands, and it cannot honestly be scoped out either, because no deployment can assert that recovery does not apply to it. It stays red until the primitive exists, an extension provides it, or NIST CSF is removed from `enabled_frameworks`.

### ISO 42001 and the `pulsar/ai-governance` extension

Pulsar ships AI Management System contracts, and this is a genuine differentiator among PHP frameworks. What it is not is a working AI governance implementation, and earlier revisions of this page read as though it were.

The extension is **off by default** and is not in the default `enabled_products` list. When it is not installed, all thirteen ISO 42001 controls are unsatisfied. When it is installed, its shipped stores are `InMemoryModelRegistry`, `InMemoryImpactAssessmentStore`, `InMemoryExplainabilityStore` and `InMemoryDataGovernanceStore` — usable for development, and evidence of nothing after a restart.

One contract has **no implementation anywhere in the repository**: `MonitoringHookInterface`. Clause 9.1 (Monitoring and Measurement), Annex A.7 (AI System Operation and Monitoring) and Clause 10.1 (Continual Improvement) all rest on it, and no deployment can satisfy them today. Earlier revisions cited that interface as though citing it discharged the clause. It does not, and that substitution is the exact defect [ADR-0041](adr/0041-the-token-vault-takes-a-connection.md) recorded.

#### What the risk classification decides

`AiModelRiskLevel` carries the four tiers of the EU AI Act (Regulation (EU) 2024/1689), and until recently nothing branched on the value — a model classified `unacceptable` reached production exactly like any other. Two deployment gates now read it, and **neither has a configuration key**, because an Article 5 prohibition is not an operator preference and the obligations of a high-risk system do not lapse because `require_model_card` ships off:

- **`prohibited_practice`** refuses any model classified `unacceptable`. `InMemoryModelRegistry` holds the same invariant independently — it refuses to register such a model as already live, refuses any transition into production, and withdraws a live model from production if it is reclassified as prohibited — because the registry is reachable without going through the lifecycle manager. No artefact unblocks either refusal: the Act bans the practice rather than conditioning it.
- **`high_risk_obligations`** refuses a model classified `high` until it carries an impact assessment on record (Article 9), an attached model card (Article 11 and Annex IV) and at least one registered monitoring hook (Article 72(3)). The refusal names the obligations that are actually unmet.

Two consequences an operator should expect. First, because no `MonitoringHookInterface` implementation ships, **a high-risk model cannot be deployed through this extension until the integrator writes one** — that is the Article 72(3) gap above, now visible at the gate rather than only in this table. Second, `register()` still accepts a high-risk system recorded as already running with its obligations unmet: an inventory that refused to record a live system could not govern it, and an unmet condition on a permitted system is a gap to record, not a fact to deny. Authorising the _transition_ into production is `AiLifecycleManagerInterface::deploy()`, and that runs the gates.

All of this applies only where the operator has listed the extension in `enabled_products`. It is `"kind": "product"` and does not load otherwise.

It runs at `verified` tier in `config/extensions.php`, and it used to need `core`. The entry that granted it was written on a wrong diagnosis: it recorded deny-by-default as refusing an extension the ids it registers for itself, which the sandbox has never done. What actually broke was `ExtensionConfigRegistry` — the object every bundled provider reads its own `config/<name>.php` out of — falling into no category of the restriction map, so `AiLifecycleManagerInterface` threw `CapabilityDeniedException` on the first contract its factory asked for and the gates above could not execute anywhere. Classifying that registry, and narrowing it to the sections the receiving extension itself ships, removed the cause; the `core` grant was withdrawn rather than left standing as a privilege nothing needed. See [ADR-0023](adr/0023-extension-trust-tiers.md). `ExtensionSandboxDriftTest` and `ShippedDeploymentGateTest` are what hold the tier where it is.

**What the integrator must provide:** concrete AI provider integrations; durable stores in place of every `InMemory*` binding; a `MonitoringHookInterface` implementation; domain-specific deployment gates; organizational AI policy documentation; and model card content per deployed system.

### The EU AI Act, and which of its obligations actually bind

The Act arrived in stages, and a digital omnibus in force since **27 July 2026** moved one of them again. The result is counter-intuitive enough that it decides the whole shape of `AiActMapping`:

| Obligation                                        | Applies from      |
| ------------------------------------------------- | ----------------- |
| Article 4 — AI literacy                           | 2 February 2025   |
| Article 5 — prohibited practices                  | 2 February 2025   |
| Chapter V — general-purpose model providers       | 2 August 2025     |
| **Article 50 — transparency**                     | **2 August 2026** |
| Chapter III high-risk — Annex III use cases       | 2 December 2027   |
| Chapter III high-risk — Annex I safety components | 2 August 2028     |

The omnibus deferred the high-risk regime. It did **not** touch Article 50. So the chapter most deployments have been preparing for is the one that no longer binds today, and the one hardly anyone prepared for is in force.

**Twenty of the twenty-two controls name an operator artefact rather than a probe.** That is the honest count, not a gap. Almost every AI Act duty is discharged in conduct or in documents — do not engage in this practice, hold this technical file, register in that database, report this incident within fifteen days — and no framework observes any of them. The deferred Chapter III controls are still declared, each stating **BINDS FROM** its own date, because a deployment building for December 2027 needs the list; none is graded as a present failure.

#### Article 50 is the part Pulsar carries

Article 50's duties are discharged in what a response contains, which is where a framework already is. `AiTransparencyInterface` (in the `ai-governance` extension) holds what each surface declares:

- whether it interacts directly with natural persons — Article 50(1);
- what it generates, of audio, image, video or text — Article 50(2);
- which exemption, if any, it relies on.

`AiTransparencyPolicy` **refuses** an incoherent declaration. "This surface talks to people, claims no exemption, and carries no notice" is not a state Article 50(1) permits, so it is not a value the type holds. The law-enforcement exemption is refused outright on a surface available to the public to report a criminal offence, which Article 50(1) carves back out of it in terms.

The four exemptions are not interchangeable, and each discharges only the paragraph that grants it:

| Exemption                  | 50(1) disclosure | 50(2) marking |
| -------------------------- | ---------------- | ------------- |
| none                       | —                | —             |
| obvious from context       | discharged       | —             |
| assistive editing only     | —                | discharged    |
| law-enforcement authorised | discharged       | discharged    |

Obviousness excuses telling someone they are talking to a machine; it does not leave a generated video unmarked, because a viewer's suspicion is not a machine-readable mark. Every exemption that excuses something names the artefact that must justify it — an exemption a deployment could assert silently would turn the obligation into an opt-out.

#### What the marking is, and what it is not

`SyntheticContentMark` marks **at the delivery boundary**. Nothing is embedded in the pixels of an image or the samples of an audio signal, and nothing survives a re-encode or a screenshot. Article 50(2) asks for marking that is "effective, interoperable, robust and reliable _as far as this is technically feasible_", and the boundary is as far as a server-side PHP framework reaches.

What is delivered in full is the machine-readable and detectable limb: an RFC 8941 structured-field assertion leading with `ai-generated=?1`, naming the model, the surface and the generation time. **The robustness limb for image, audio and video is yours**, discharged with a provenance standard such as C2PA applied where the media is produced. The `ai-act-art-50-2` control says so where an assessor will read it.

#### What is probed, and what is not

Exactly one Article 50 control carries a probe, and it claims only what it proves:

- `ai-act-art-50-capability` — **probed**. The extension is active and a transparency contract resolved, so positions can be declared and marks minted.
- `ai-act-art-50-1`, `ai-act-art-50-2` — **operator artefacts**. Whether a person actually saw the notice, and whether real output actually carried the mark, happen where Pulsar cannot look.

Two candidate observations were written and removed before they shipped, and the reasons are worth stating. One would have reported that every surface owing a notice has one — always true, because the constructor already refuses otherwise, and a check that cannot fail is indistinguishable from no check. The other would have reported how many surfaces are declared, which the core evidence gatherer has no mechanism to measure: `ComplianceScope` produces only `Asserted` observations, which by construction cannot support `Satisfied`. See [ADR-0058](adr/0058-the-ai-act-obligation-that-binds-is-article-50.md).

As with ISO 42001, all of this applies only where the operator has listed `pulsar/ai-governance` in `enabled_products`. A deployment that runs AI systems and does not enable it has no way to express a duty it already owes, and `AiTransparencyProbe` reports exactly that rather than staying silent.

### The remaining frameworks

PSD2, eIDAS, MDR, HL7/FHIR, ISO 13485, DORA, SWIFT CSP and CCPA have mappings in the tree and are assessed the same way when enabled. Their capability dependencies are the rows above; there is no separate mechanism and no additional coverage. Two dependencies are worth stating explicitly because they have no implementation at all:

- **CCPA 1798.100 / GDPR Art. 15** rest on `DsarStoreInterface`, which nothing implements.
- **DORA and SWIFT CSP resilience testing** rest on the backup and restore primitive that does not exist.

## Publishing a result: the compliance badge block

The `pulsar/cms` extension ships a `compliance-badge` block for putting a compliance statement on a public page. It renders **only** what an assessment observed.

An author picks a framework and, optionally, a link. They cannot supply the outcome text, a label, or a logo: those fields were removed rather than validated, because a free-text status is an unbounded claim and an uploaded seal reading "SOC 2 CERTIFIED" is a stronger claim than any text beside it. A framework the report has never emitted a finding for renders as **not assessed**, naming itself, rather than disappearing — a bare framework name on a marketing page reads as a claim to the reader. The assessment date is always rendered, so a page that has drifted from its evidence says so on its face.

The block reads the JSON artefact, whose path is `compliance_report_path` in `config/cms.php`:

```bash
php bin/pulsar compliance:report --format=json > var/compliance/report.json
```

Regenerate it as a deployment step. Assessing on demand is deliberately not an option: gathering runs a database query, executes health checks and recomputes an HMAC per stored audit record, which is not work to do on a request from the open internet.

If no readable report exists at that path, every badge renders as not assessed. Clearing the config value leaves the block unregistered, so it cannot be placed at all — the right outcome for a site that does not assess itself. Bind your own `ObservedComplianceSourceInterface` if your reports live somewhere other than a file on disk.

## Capability-to-source mapping

| Capability            | Primary source files                                                                                    |
| --------------------- | ------------------------------------------------------------------------------------------------------- |
| Control declarations  | `src/Compliance/Frameworks/`, `src/Compliance/Control/ControlDeclaration.php`                           |
| Probes and evidence   | `src/Compliance/Probe/`, `src/Compliance/Evidence/ControlEvidenceGatherer.php`                          |
| Report                | `src/Compliance/Report/`, `src/Console/Command/ComplianceReportCommand.php`                             |
| Encryption / key mgmt | `src/Security/Crypto/`, `src/Core/Wiring/SecurityWiring.php`, `config/security.php`                     |
| Session security      | `src/Security/Session/`, `src/Config/SessionConfig.php`                                                 |
| CSRF protection       | `src/Security/Csrf/`, `src/Config/CsrfConfig.php`                                                       |
| Audit logging         | `src/Security/Audit/AuditEntry.php`, `src/Security/Audit/AuditChainVerifier.php`                        |
| Data retention        | `src/DataProtection/RetentionPolicyInterface.php`, `src/DataProtection/DataPurgeInterface.php`          |
| Consent management    | `src/DataProtection/ConsentManagerInterface.php`, `src/DataProtection/InMemoryConsentManager.php`       |
| Incident management   | `src/Security/Incident/IncidentReporterInterface.php`, `src/Security/Incident/FileIncidentReporter.php` |
| Deploy gates          | `src/Deploy/Check/`, `src/Config/DeployConfig.php`                                                      |
| Resilience            | `src/Resilience/`                                                                                       |
| Observability         | `src/Observability/`                                                                                    |
| Tenancy               | `src/Tenancy/`                                                                                          |
| AI governance         | `extensions/ai-governance/src/`                                                                         |

## Notes

1. **Framework versus host responsibility.** Rows marked `H` are the ones no framework can carry. Pulsar provides interfaces, defaults and enforcement mechanisms; policies, personnel, physical controls, infrastructure and contracts are the deploying organization's, and an assessor will ask that organization for them directly.

2. **`S` does not mean "done".** It means "active without operator action". A supported capability still has to be applied to your domain: Pulsar emits audit events for its own actions, not for yours.

3. **Regulatory currency.** This page reflects requirements as of Pulsar 1.0.0-rc.12. Regulatory frameworks evolve; consult current regulation texts and qualified counsel.

4. **Certification scope.** Framework-level controls do not constitute compliance, and neither this document nor `compliance:report` is a certification. The report states what was observed in one deployment at one moment. That is a useful input to an audit and is not a substitute for one.
