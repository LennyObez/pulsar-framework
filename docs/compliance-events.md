# Compliance Events

## Overview

Pulsar's compliance event system provides a structured, auditable event model for regulated domains. Every compliance event extends the `ComplianceEvent` base class and carries a unique event ID, timestamp, correlation ID, and cryptographic nonce for replay protection.

The system supports controls for:

- **GDPR** -- Consent management, data subject rights, breach notification, pseudonymization
- **HIPAA** -- PHI access logging, breach notification, security incident response, audit review
- **PCI-DSS** -- Cardholder data access tracking, key management, vulnerability management
- **SOX** -- Financial data change tracking, internal control testing, audit trail verification
- **DORA** -- ICT incident management, resilience testing, third-party risk assessment
- **AML/KYC** -- Customer verification, transaction screening, sanctions checks, suspicious activity reporting

All compliance events require envelope-based dispatch (`#[RequiresEnvelope]`), which adds integrity hashing, correlation metadata, and schema versioning to every event in transit.

## Event taxonomy

### GDPR events

| Event Class                | Event Type                   | Description                                                          |
| -------------------------- | ---------------------------- | -------------------------------------------------------------------- |
| `ConsentGranted`           | `consent_granted`            | Records that a data subject granted consent for a processing purpose |
| `ConsentRevoked`           | `consent_revoked`            | Records withdrawal of consent for a processing purpose               |
| `DataAccessRequested`      | `data_access_requested`      | Records filing of a data subject access request (DSAR)               |
| `DataDeletionRequested`    | `data_deletion_requested`    | Records filing of a right-to-erasure request                         |
| `DataPortabilityRequested` | `data_portability_requested` | Records filing of a data portability request                         |
| `BreachDetected`           | `breach_detected`            | Records detection of a personal data breach                          |
| `BreachNotified`           | `breach_notified`            | Records notification of a breach to a supervisory authority          |
| `DpiaCompleted`            | `dpia_completed`             | Records completion of a Data Protection Impact Assessment            |

### HIPAA events

| Event Class            | Event Type               | Description                                    |
| ---------------------- | ------------------------ | ---------------------------------------------- |
| `PhiAccessed`          | `phi_accessed`           | Records access to Protected Health Information |
| `PhiModified`          | `phi_modified`           | Records modification of PHI                    |
| `PhiDisclosed`         | `phi_disclosed`          | Records disclosure of PHI to a third party     |
| `BreachNotification`   | `breach_notification`    | Records a HIPAA breach notification event      |
| `SecurityIncident`     | `security_incident`      | Records a security incident affecting ePHI     |
| `AuditReviewCompleted` | `audit_review_completed` | Records completion of a HIPAA audit review     |

### PCI-DSS events

| Event Class                | Event Type                   | Description                                 |
| -------------------------- | ---------------------------- | ------------------------------------------- |
| `CardDataAccessed`         | `card_data_accessed`         | Records access to cardholder data           |
| `AccessControlChanged`     | `access_control_changed`     | Records a change to access control settings |
| `KeyRotated`               | `key_rotated`                | Records a cryptographic key rotation event  |
| `PenetrationTestCompleted` | `penetration_test_completed` | Records completion of a penetration test    |
| `VulnerabilityFound`       | `vulnerability_found`        | Records discovery of a vulnerability        |

### SOX events

| Event Class             | Event Type                | Description                                                |
| ----------------------- | ------------------------- | ---------------------------------------------------------- |
| `FinancialDataModified` | `financial_data_modified` | Records modification of financial data with snapshots      |
| `AccessControlChanged`  | `access_control_changed`  | Records a change to access controls over financial systems |
| `AuditTrailVerified`    | `audit_trail_verified`    | Records verification of audit trail integrity              |
| `ControlTestCompleted`  | `control_test_completed`  | Records completion of an internal control test             |

### DORA events

| Event Class               | Event Type                  | Description                                         |
| ------------------------- | --------------------------- | --------------------------------------------------- |
| `IctIncidentDetected`     | `ict_incident_detected`     | Records detection of an ICT-related incident        |
| `RecoveryInitiated`       | `recovery_initiated`        | Records initiation of a recovery procedure          |
| `ResilienceTestCompleted` | `resilience_test_completed` | Records completion of a resilience test             |
| `ThirdPartyRiskAssessed`  | `third_party_risk_assessed` | Records completion of a third-party risk assessment |

### AML/KYC events

| Event Class                  | Event Type                     | Description                                            |
| ---------------------------- | ------------------------------ | ------------------------------------------------------ |
| `CustomerVerified`           | `customer_verified`            | Records completion of KYC customer verification        |
| `TransactionScreened`        | `transaction_screened`         | Records completion of transaction screening            |
| `SanctionsChecked`           | `sanctions_checked`            | Records completion of a sanctions list check           |
| `SuspiciousActivityDetected` | `suspicious_activity_detected` | Records detection of suspicious activity requiring SAR |

## Authorization events

Authorization events live in `Pulsar\Auth\Authorization\Event` and implement `EnvelopeRequiredEvent` directly (rather than extending `ComplianceEvent`). They support controls for SOX audit trail requirements, HIPAA access logging, and PCI-DSS privileged access tracking.

| Event Class               | Description                                             |
| ------------------------- | ------------------------------------------------------- |
| `AuthenticationSucceeded` | Dispatched on successful authentication                 |
| `AuthenticationFailed`    | Dispatched on failed authentication attempt             |
| `AuthorizationGranted`    | Dispatched when the Gate grants access                  |
| `AuthorizationDenied`     | Dispatched when the Gate denies access                  |
| `PrivilegeEscalated`      | Dispatched when a user's roles are escalated in-session |
| `StepUpAuthRequired`      | Dispatched when step-up authentication is triggered     |

Each authorization event carries its own `correlationId` and `nonce` for replay safety, and provides a `create()` factory that generates these automatically using a cryptographically secure `Randomizer`.

```php
use Pulsar\Auth\Authorization\Event\AuthorizationGranted;

$event = AuthorizationGranted::create(
    identityId: $user->id,
    permission: 'financial.ledger.write',
    resource: 'ledger:2024-q4',
    grantReason: 'role:finance-admin',
    correlationId: $context->correlationId->value,
);
```

## Emitting compliance events

All compliance events are marked with `#[RequiresEnvelope]` and must be dispatched via `EventEnvelope`. The envelope adds a SHA-256 payload hash computed from canonical serialization (event type + schema version + recursively key-sorted JSON payload).

```php
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;
use Pulsar\Security\Compliance\Event\Gdpr\ConsentGranted;

// 1. Build the compliance event
$event = new ConsentGranted(
    eventId: $uuid,
    occurredAt: new \DateTimeImmutable(),
    correlationId: $context->correlationId->value,
    nonce: bin2hex(random_bytes(16)),
    subjectId: $user->id,
    purpose: 'marketing_emails',
    legalBasis: 'consent',
    consentScope: 'email_newsletter',
    expiresAt: new \DateTimeImmutable('+1 year'),
);

// 2. Wrap in an EventEnvelope for integrity protection
$envelope = EventEnvelope::wrap(
    eventType: 'gdpr.consent_granted',
    schemaVersion: ConsentGranted::SCHEMA_VERSION,
    payload: $event->toArray(),
    metadata: EventMetadata::fromRequestContext($requestContext),
);

// 3. Dispatch via the event dispatcher
$dispatcher->dispatch($envelope);
```

The `EventEnvelope` computes a `payloadHash` using SHA-256 over a canonical input string: `"{eventType}|{schemaVersion}|{sorted-json-payload}"`. This hash is included in the envelope and can be verified downstream to detect tampering.

## Pseudonymization

The pseudonymization service (`PseudonymizationService`) provides pseudonym generation backed by libsodium's keyed BLAKE2b hashing (`sodium_crypto_generichash` with a key parameter). It supports controls for GDPR Article 4(5) pseudonymization and HIPAA Safe Harbor de-identification.

### How it works

1. A purpose-specific subkey is derived from the application `MasterKey` using `sodium_crypto_kdf_derive_from_key` with sub-key ID `3` and context `pseudo__`.
2. A 16-byte random salt is generated per subject.
3. The pseudonym is computed: `BLAKE2b(subjectId + salt, derivedKey)`, truncated to 32 hex characters.
4. The salt is encrypted at rest via `EncryptorInterface` before being stored in the lookup table.
5. On subsequent calls for the same subject, the existing pseudonym is returned.

### Reverse lookup

The `resolve()` method performs reverse lookup from pseudonym to subject ID. Every resolution is audit-logged as a `DataAccess` event with action `pseudonym.resolve`.

```php
use Pulsar\Security\Compliance\Pseudonymization\PseudonymizationServiceInterface;

// Pseudonymize a subject identifier
$pseudonym = $pseudonymizer->pseudonymize($userId);

// Reverse lookup (audit-logged)
$originalId = $pseudonymizer->resolve($pseudonym);
```

### Key isolation

The pseudonymization subkey is derived with sub-key ID `3`, which is isolated from the encryption subkey (ID `1`) and the audit HMAC chain subkey (ID `2`). Compromise of one subkey does not compromise the others.

## Right to forget

The `ForgetService` implements GDPR Article 17 right-to-erasure by deleting pseudonym mappings while preserving audit chain integrity.

### Workflow

```
Subject requests erasure
        |
        v
ForgetService::forget($subjectId)
        |
        v
+--- Lookup mapping by subjectId ---+
|                                    |
| Not found? -> ComplianceException  |
|                                    |
| Found -> extract pseudonym         |
|                                    |
+------------------------------------+
        |
        v
Delete mapping from lookup store
        |
        v
Emit audit event:
  event:    DataModification
  action:   pseudonym.forget
  resource: $subjectId
  metadata: { pseudonym: $pseudonym }
        |
        v
Return ForgetResult:
  subjectId, pseudonym,
  forgottenAt, auditEntryId
```

The audit trail records that the erasure occurred (including the pseudonym that was deleted) without retaining the actual mapping data. This preserves evidence that the deletion was performed for regulatory accountability.

```php
use Pulsar\Security\Compliance\Pseudonymization\ForgetServiceInterface;

$result = $forgetService->forget($subjectId);

// $result->auditEntryId links to the audit trail
// $result->forgottenAt records the exact deletion time
```

## Retention management

Retention policies define how long compliance records must be kept before they may be purged. Each policy is versioned for auditable policy changes.

### Default retention periods

| Regulation | Period  | Policy Reference                                             |
| ---------- | ------- | ------------------------------------------------------------ |
| SOX        | 7 years | SOX Section 802 -- audit work papers and financial records   |
| HIPAA      | 6 years | 45 CFR 164.530(j) -- policies and compliance documentation   |
| DORA       | 5 years | DORA Article 12 -- ICT-related incident records              |
| AML        | 5 years | 4AMLD Article 40 -- transaction and identity records         |
| PCI-DSS    | 1 year  | PCI DSS Requirement 10.7 -- audit trail history              |
| GDPR       | 1 year  | GDPR Article 5(1)(e) -- storage limitation principle minimum |

### Custom policies

Build a `RetentionSchedule` with custom `RetentionPolicy` instances:

```php
use Pulsar\Security\Compliance\Retention\RetentionSchedule;
use Pulsar\Security\Compliance\Retention\RetentionPolicy;

$schedule = new RetentionSchedule([
    new RetentionPolicy(
        policyId: 'hipaa-custom',
        version: 2,
        regulation: 'HIPAA',
        retentionPeriodDays: 2555, // 7 years
        effectiveDate: new \DateTimeImmutable('2025-01-01'),
        description: 'Extended HIPAA retention per organizational policy',
    ),
    // ...additional policies
]);
```

Or use `RetentionSchedule::default()` for standard regulatory defaults.

### Purge workflow

The `RetentionManager` evaluates retention policies and orchestrates purges:

```php
use Pulsar\Security\Compliance\Retention\RetentionManager;

// Dry-run: see what would be purged without executing
$dryResult = $manager->purge('HIPAA', dryRun: true, operatorIdentity: 'admin@corp');

// Actual purge: emits an audit event recording the operation
$result = $manager->purge('HIPAA', dryRun: false, operatorIdentity: 'admin@corp');

// Check if a specific record has expired
$expired = $manager->isExpired('SOX', $record->createdAt);
```

On actual purge (not dry-run), the manager emits a `DataModification` audit event with action `retention.purge`, recording the policy applied, affected date range, record count, and operator identity.

## Evidence export

The `EvidenceExporterInterface` produces tamper-evident archives with integrity hash manifests for regulatory audits.

```php
use Pulsar\Security\Compliance\Evidence\EvidenceExporterInterface;

$result = $exporter->export(
    records: $auditRecords,
    policy: $retentionManager->policyFor('SOX'),
    operatorIdentity: 'compliance-officer@corp',
);

// EvidenceExportResult contains:
// - $result->archiveId      -- unique archive identifier
// - $result->recordCount    -- number of records exported
// - $result->hashManifest   -- SHA-256 hash of serialized records
// - $result->exportedAt     -- export timestamp
// - $result->encrypted      -- whether the archive is encrypted
```

The `hashManifest` is a SHA-256 digest of the JSON-serialized records. Store this hash separately from the archive to verify integrity during audits.

The built-in `InMemoryEvidenceExporter` is provided for testing and development. Production deployments should implement `EvidenceExporterInterface` with encrypted storage and operator authentication.

## Compliance log formatters

The `ComplianceLogSink` routes log entries through regulation-specific formatters before writing to an underlying sink. When an `EncryptorInterface` is provided, the entire log entry is encrypted after formatting.

### Formatter stack

```php
use Pulsar\Observability\Log\Compliance\ComplianceLogSink;
use Pulsar\Observability\Log\Compliance\PciDssLogFormatter;
use Pulsar\Observability\Log\Compliance\GdprLogFormatter;
use Pulsar\Observability\Log\Compliance\HipaaLogFormatter;
use Pulsar\Observability\Log\Compliance\SoxLogFormatter;

$complianceSink = new ComplianceLogSink(
    underlyingSink: $fileSink,
    encryptor: $encryptor,       // optional, encrypts full entry
    new PciDssLogFormatter(),
    new GdprLogFormatter(),
    new HipaaLogFormatter(),
    new SoxLogFormatter(),
);
```

### PCI-DSS: card masking

`PciDssLogFormatter` performs irreversible masking on cardholder data:

- **PAN numbers**: Detected via regex (13-19 digit sequences), validated with Luhn check, masked to show only the last 4 digits (e.g., `************1234`)
- **CVV/CVC fields**: Fully masked to `***`
- **Expiry fields**: Masked to `**/**`

Masking is applied to both the log message and all context values recursively.

### GDPR: pseudonymization

`GdprLogFormatter` replaces personal data fields with SHA-256 based pseudonyms (truncated to 16 hex characters, prefixed with `pseudonym_`). Default fields: `user_id`, `email`, `subject_id`, `name`, `ip_address`. Custom field lists can be provided via the constructor.

### HIPAA: PHI markers

`HipaaLogFormatter` detects PHI categories in log context and:

- Adds a `phi_access: true` flag when any PHI key is present
- Pseudonymizes patient identifier fields (`patient_id`, `patient_name`, `ssn`, `mrn`, `health_plan_id`) using SHA-256 hashing with a `patient_` prefix

### SOX: snapshot handling

`SoxLogFormatter` adds financial data classification metadata and restructures change snapshots:

- Classifies data as `financial`, `change_record`, or `operational` based on context keys
- Restructures `before`/`after` keys into a nested `change_snapshot` object
- Adds `sox_controlled: true` to all processed entries

## Snapshot capture

The `SnapshotCapture` utility produces immutable, classification-aware snapshots of entity state. It supports controls for SOX Section 302/404 internal controls over financial reporting.

### Data classification

Every field in a snapshot carries a `DataClassification` level:

| Level          | Snapshot Behavior                |
| -------------- | -------------------------------- |
| `Public`       | Excluded from snapshot entirely  |
| `Internal`     | Captured as-is                   |
| `Confidential` | Captured as-is                   |
| `Restricted`   | Value replaced with `[REDACTED]` |

### Capturing snapshots

```php
use Pulsar\Security\Compliance\Snapshot\SnapshotCapture;
use Pulsar\Security\Compliance\Snapshot\ClassifiedField;
use Pulsar\Security\Compliance\DataClassification;

$snapshot = SnapshotCapture::capture(
    entityType: 'LedgerEntry',
    entityId: 'ledger-42',
    new ClassifiedField('amount', 15000.00, DataClassification::Confidential),
    new ClassifiedField('currency', 'USD', DataClassification::Internal),
    new ClassifiedField('account_number', '1234567890', DataClassification::Restricted),
    new ClassifiedField('description', 'Q4 adjustment', DataClassification::Public),
);

// Result:
// - amount: 15000.00 (Confidential - captured as-is)
// - currency: USD (Internal - captured as-is)
// - account_number: [REDACTED] (Restricted - redacted)
// - description: excluded (Public - not in snapshot)
```

### Before/after diffs

For SOX change tracking, capture snapshots before and after a mutation:

```php
$before = SnapshotCapture::capture('LedgerEntry', 'ledger-42', ...$fieldsBefore);
// ... perform mutation ...
$after = SnapshotCapture::capture('LedgerEntry', 'ledger-42', ...$fieldsAfter);

$diff = SnapshotCapture::diff($before, $after);

// $diff->before and $diff->after are both Snapshot instances
// Pass $diff->toArray() to the SOX FinancialDataModified event
```

The `BeforeAfterSnapshot` pairs two `Snapshot` instances and serializes to a standard `{ before: ..., after: ... }` structure, which the `SoxLogFormatter` automatically restructures into `change_snapshot` when it appears in log context.
