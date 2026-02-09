# Compliance Guide

This document covers regulatory compliance considerations for Pulsar CMS deployments, including GDPR, financial record retention, data classification, and data subject rights.

## GDPR Compliance

Pulsar CMS is designed for deployment in regulated European environments. The following features support GDPR compliance.

### Personal Data Inventory

| Data Category         | Where Stored                    | Lawful Basis                              |
| --------------------- | ------------------------------- | ----------------------------------------- |
| CMS user accounts     | `cms_users` table               | Legitimate interest (administration)      |
| Content author IDs    | `cms_contents`, `cms_revisions` | Legitimate interest (content attribution) |
| Comment author info   | `cms_comments` table            | Consent (comment submission)              |
| Guest commenter email | `cms_comments` table            | Consent (optional field)                  |
| Comment IP address    | `cms_comments` table            | Legitimate interest (anti-abuse)          |
| Customer order data   | `cms_orders`, `cms_order_items` | Contract performance                      |
| Customer payment refs | `cms_orders` table              | Contract performance                      |
| Billing addresses     | `cms_invoices` table            | Legal obligation (tax records)            |
| Audit log actor IDs   | Audit log storage               | Legitimate interest (security)            |
| Search queries        | `cms_search_analytics`          | Legitimate interest (service improvement) |
| Media EXIF data       | Stripped by default             | Not stored (privacy by design)            |

### Data Minimization

Pulsar CMS implements data minimization through:

1. **EXIF stripping**: GPS coordinates and device identifiers are removed from uploaded images by default
2. **IPv6 subnet masking**: Client fingerprints use /64 subnets instead of full IPv6 addresses
3. **Optional email collection**: Guest commenter email is configurable and not required by default
4. **PII-aware exports**: The export system can exclude PII by default

### Right of Access (Article 15)

Data subjects can request a copy of all personal data held about them.

#### GDPR Data Export

Administrators can export all data for a specific user:

```
POST /admin/cms/tools/gdpr/export
Content-Type: application/json

{
    "user_id": "user-uuid"
}
```

This exports:

- User profile information
- Content authored by the user (with metadata)
- Comments submitted by the user
- Orders placed by the user
- Audit log entries involving the user
- Editorial reviews by the user

The export is returned in a machine-readable JSON format suitable for data portability (Article 20).

### Right to Erasure (Article 17)

Data subjects can request deletion of their personal data, subject to legal retention requirements.

#### GDPR Data Erasure

```
POST /admin/cms/tools/gdpr/erase
Content-Type: application/json

{
    "user_id": "user-uuid"
}
```

The erasure process:

1. **Content**: Author attribution is anonymized (author ID replaced with a placeholder). Content body is preserved for operational continuity.
2. **Comments**: Author name, email, and IP are erased. Comment body is preserved if approved and part of a public discussion, or fully deleted if in pending/rejected/spam status.
3. **Orders**: Customer name and email are anonymized. Order records are preserved for financial retention requirements (see below).
4. **Invoices**: Billing details are anonymized where legally permissible. Invoices required for tax compliance are retained with anonymized customer data.
5. **Audit logs**: Actor references are anonymized. The audit entries themselves are retained for security compliance.
6. **Search analytics**: Any user-identifiable query data is purged.

#### Retention Exceptions

Erasure does not apply to data retained for:

- Legal obligations (tax invoices, financial records)
- Exercising legal claims (order dispute records)
- Legitimate security interests (audit logs, fraud detection)

These exceptions are documented in the erasure response so administrators can communicate them to data subjects.

### Privacy by Design

Pulsar CMS implements privacy by design (Article 25) through:

| Feature            | Implementation                                              |
| ------------------ | ----------------------------------------------------------- |
| Data minimization  | EXIF stripping, IPv6 masking, optional fields               |
| Purpose limitation | PII-aware exports, scoped data access                       |
| Storage limitation | Configurable retention policies                             |
| Integrity          | Audit logging, integrity checks                             |
| Confidentiality    | RBAC, step-up auth, encryption at rest (via infrastructure) |

### Data Protection Impact Assessment (DPIA)

Operators handling large-scale personal data should conduct a DPIA. Key areas to assess:

1. **Comment processing**: Volume of personal data from commenters
2. **Commerce transactions**: Customer payment and billing data
3. **User tracking**: Search analytics and audit logging
4. **Content classification**: Handling of confidential/internal data

### Consent Management

For cookie consent and tracking consent, Pulsar CMS:

- Does not set tracking cookies by default
- Page caching uses server-side mechanisms, not cookies
- Search analytics are aggregate and do not use browser fingerprinting
- Third-party integrations (analytics, CDN) require separate consent handling

## Financial Record Retention

### Order Records

Commerce orders and their associated data must be retained according to local regulations:

| Jurisdiction | Retention Period | Regulation                |
| ------------ | ---------------- | ------------------------- |
| EU (general) | 7-10 years       | National commercial codes |
| France       | 10 years         | Code de Commerce          |
| Germany      | 10 years         | HGB Section 257           |
| UK           | 6 years          | Companies Act 2006        |
| US           | 7 years          | IRS requirements          |

### Invoice Records

Invoices are financial documents with strict retention requirements:

- Invoices must be stored in their original form
- The `InvoiceService` generates invoices with unique numbers and timestamps
- Invoice data (amounts, tax breakdown, parties) must be immutable
- Anonymized customer data is permitted if the financial transaction details are preserved

### Tax Records

Tax calculation records should be retained alongside orders:

- Applied tax rates and amounts per line item
- Tax category classification
- Customer jurisdiction information
- Total tax collected

### Order Export

For accounting integration, use the order export feature:

```
GET /admin/cms/orders/export
```

This generates a CSV suitable for import into accounting software, containing all required financial fields.

## Data Classification

Pulsar CMS supports data classification at the content level:

| Level          | Description                     | Access Control                |
| -------------- | ------------------------------- | ----------------------------- |
| `public`       | No access restrictions          | Available to all visitors     |
| `internal`     | Organization-internal content   | Requires authentication       |
| `confidential` | Restricted access, audit-logged | Requires specific permissions |

### Classification Recommendations

| Content Type            | Recommended Classification |
| ----------------------- | -------------------------- |
| Public blog posts       | `public`                   |
| Internal policies       | `internal`                 |
| Financial reports       | `confidential`             |
| Legal documents         | `confidential`             |
| Customer communications | `internal`                 |

### Handling Classified Content

- **Internal** content is not indexed in public sitemaps
- **Confidential** content access is audit-logged
- Export operations respect classification: confidential content requires explicit PII inclusion flag
- Backup operations include classification metadata for proper handling

## Audit Trail Requirements

### What Is Logged

See the [Audit Events Reference](audit-events.md) for the complete taxonomy. Key compliance-relevant events:

| Category          | Events                                                   |
| ----------------- | -------------------------------------------------------- |
| Access control    | Authentication, authorization decisions                  |
| Data modification | Content CRUD, comment moderation, settings changes       |
| Security events   | 2FA lifecycle, sanitizer bypass detection, bot detection |
| Financial events  | Order creation, payment, refund, invoice generation      |

### Audit Log Integrity

Recommendations for maintaining audit log integrity:

1. **Append-only storage**: Audit logs should be stored in append-only or write-once media
2. **Evidence hashing**: Enable evidence hashing for tamper detection
3. **Off-site backup**: Replicate audit logs to a separate, secure location
4. **Access control**: Restrict who can read audit logs (no modification permitted)
5. **Retention alignment**: Configure retention to meet the most stringent applicable regulation

### Monitoring

Set up monitoring for:

- Multiple failed authentication attempts (possible brute-force)
- Failed 2FA verifications (`cms.2fa.verify_failed`)
- Honeypot triggers (`cms.comment.honeypot_triggered`)
- Sanitizer bypass detections (`cms.security.sanitizer_bypass_detected`)
- Unusual settings changes (`cms.settings.updated`)
- Force-unlock events (`cms.content.lock.force_unlocked`)

## Cross-Border Data Transfer

If your CMS deployment serves users across jurisdictions:

1. **Data residency**: Deploy the database in the appropriate jurisdiction
2. **CDN configuration**: Use CDN providers with data processing agreements
3. **Backup location**: Store backups in compliant locations
4. **Standard contractual clauses**: Ensure third-party services have appropriate agreements

## Compliance Checklist

### Before Go-Live

- [ ] Complete Data Protection Impact Assessment (if required)
- [ ] Configure data retention policies
- [ ] Set up audit log monitoring and alerting
- [ ] Enable editorial workflow for content governance
- [ ] Enable event sourcing for immutable audit trail
- [ ] Configure GDPR export and erasure endpoints
- [ ] Review and configure data classification levels
- [ ] Set up backup schedule with tested restore procedure
- [ ] Configure comment privacy settings (guest email, IP retention)
- [ ] Enable 2FA for all administrative accounts
- [ ] Review plugin capabilities and provenance

### Ongoing

- [ ] Regular audit log review
- [ ] Timely response to data subject requests
- [ ] Annual DPIA review (if applicable)
- [ ] Backup integrity verification
- [ ] Security patch management
- [ ] Access control review (user roles and permissions)

## Next Steps

- [Security Model](security-model.md) - Security controls supporting compliance
- [Audit Events Reference](audit-events.md) - Complete audit event taxonomy
- [Threat Model](threat-model.md) - Risk assessment
- [Import/Export Guide](../user/import-export-guide.md) - PII handling in exports
