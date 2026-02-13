# Control packs

Control packs are domain-specific starter kits for regulated industries. They provide entity scaffolds, configuration stubs, test templates, and compliance controls coverage documentation for common domain patterns.

> **Important**: Control packs are **scaffolds**, not certifications. They provide a starting point that supports controls for specific regulatory frameworks. They do **not** ensure, guarantee, or certify compliance with any regulation. See [Compliance disclaimer](#compliance-disclaimer) below.

## Available packs

| Pack         | Domain                    | Compliance Frameworks Supported  |
| ------------ | ------------------------- | -------------------------------- |
| `banking`    | Financial services        | PCI-DSS, PSD2, DORA              |
| `healthcare` | Healthcare applications   | HIPAA, MDR                       |
| `legal`      | Legal practice management | Document Retention, Audit Trail  |
| `saas`       | Multi-tenant SaaS         | Tenant Isolation, Data Residency |
| `api`        | API-only applications     | API Security, Rate Limiting      |

## Quick start

### Create a project with a control pack

```bash
pulsar new my-banking-app --pack=banking
```

This runs the standard project scaffolding and then installs the banking control pack on top. The pack adds domain-specific entities, configuration, tests, and compliance documentation.

### Combine with presets

Control packs work alongside project presets:

```bash
pulsar new my-api --preset=api --pack=api
```

The preset controls the base project structure (web, api, or minimal). The pack adds domain-specific content on top.

### List available packs

```bash
pulsar new --list-packs
```

Displays all installed packs with their descriptions and supported compliance frameworks.

## Pack anatomy

Each control pack follows a standard directory structure:

```
resources/packs/{name}/
  pack.json           # Pack manifest
  config/             # Configuration stubs
  src/                # Entity and service stubs
  tests/              # Test stubs
  docs/
    setup-guide.md    # Getting started documentation
  CONTROLS.md         # Controls coverage report
  NOT-CERTIFIED.md    # Compliance disclaimer
```

### Pack manifest (pack.json)

The manifest defines pack metadata and file mappings:

```json
{
  "name": "banking",
  "description": "Financial services starter kit with support for PCI-DSS, PSD2, and DORA controls",
  "version": "1.0.0",
  "requiredPulsarVersion": "^1.0",
  "compliancePresets": ["PCI-DSS", "PSD2", "DORA"],
  "files": {
    "src/Transaction.php": "src/Entity/Transaction.php",
    "config/encryption.php": "config/encryption.php"
  },
  "postInstallCommands": []
}
```

| Field                   | Type       | Description                                           |
| ----------------------- | ---------- | ----------------------------------------------------- |
| `name`                  | `string`   | Pack identifier                                       |
| `description`           | `string`   | Human-readable description                            |
| `version`               | `string`   | Pack version (semver)                                 |
| `requiredPulsarVersion` | `string`   | Minimum Pulsar version constraint                     |
| `compliancePresets`     | `string[]` | Regulatory frameworks this pack supports controls for |
| `files`                 | `object`   | Source path to target path mapping                    |
| `postInstallCommands`   | `string[]` | Commands to run after installation                    |

### Template variables

Pack template files support placeholder substitution:

| Variable           | Replacement                                  |
| ------------------ | -------------------------------------------- |
| `{{project_name}}` | Project name as provided to `pulsar new`     |
| `{{namespace}}`    | Project namespace (default: `App`)           |
| `{{project_slug}}` | Lowercase hyphenated version of project name |

Variables are substituted using safe string replacement (`str_replace`). No `eval()` or arbitrary code execution.

### CONTROLS.md

Each pack includes a controls coverage report that maps regulatory requirements to pack features. All entries are marked as **Scaffold** to indicate they are starting points, not production-ready implementations.

Example entry:

| Requirement             | Pack Support                 | Status   |
| ----------------------- | ---------------------------- | -------- |
| Req 10: Log and monitor | PCI-compliant logging config | Scaffold |

Every CONTROLS.md includes an **Additional Steps Needed** section listing what is required for actual regulatory compliance.

### NOT-CERTIFIED.md

Every pack includes a prominent disclaimer file explaining:

- What the pack provides (scaffolds, starting points)
- What the pack does **not** provide (certified compliance, legal advice)
- The operator's responsibilities before production use
- A liability disclaimer

## Pack details

### Banking pack

Supports controls for PCI-DSS v4.0, PSD2, and DORA.

**Entities:**

- `Transaction` — Financial transaction with amount tracking, status management, and settlement flow
- `Account` — Bank account with encrypted account numbers and balance tracking
- `PaymentIntent` — Two-phase payment flow with Strong Customer Authentication (SCA) support for PSD2
- `KycProfile` — Know Your Customer verification profile with document tracking

**Configuration:**

- `config/encryption.php` — Encryption-at-rest for sensitive financial data (account numbers, card numbers)
- `config/audit.php` — Audit trail for financial operations (transaction, account, payment, KYC events)
- `config/pci-logging.php` — PCI-DSS Requirement 10 compliant logging with card number masking

### Healthcare pack

Supports controls for HIPAA Security Rule and MDR.

**Entities:**

- `Patient` — Patient record with PHI field classification and age calculation
- `MedicalRecord` — Clinical record with confidentiality classification (Normal, Restricted, Very Restricted)
- `Appointment` — Scheduled clinical appointment with status tracking
- `Prescription` — Medication prescription with refill and expiration tracking

**Configuration:**

- `config/phi-access.php` — HIPAA-compliant PHI access logging with unusual access alerts
- `config/data-classification.php` — Data sensitivity classification (Public, Internal, Confidential, PHI, Restricted)
- `config/encryption.php` — Encryption for PHI fields (patient name, DOB, SSN, diagnosis)

### Legal pack

Supports controls for document retention and audit trail requirements.

**Entities:**

- `LegalCase` — Case management with practice area, court reference, and status tracking
- `Document` — Document with attorney-client privilege flag, litigation hold, and retention period
- `Client` — Client record with organization type tracking
- `Deadline` — Court deadline with overdue detection and reminder calculation

**Configuration:**

- `config/retention.php` — Document retention policies per category (correspondence, filings, contracts, real estate, tax, estate, trust)
- `config/audit.php` — Audit trail for case, document, client, and deadline events

### SaaS pack

Supports controls for tenant isolation and data residency.

**Entities:**

- `Tenant` — Organization with slug, plan, region, and trial tracking
- `Subscription` — Billing subscription with status, cycle, and cancellation tracking
- `Plan` — Billing plan with pricing, user limits, storage limits, and feature lists
- `Feature` — Feature flag with plan-based availability and rollout percentage

**Configuration:**

- `config/tenancy.php` — Multi-tenant isolation strategy and resolution configuration
- `config/billing.php` — Billing provider integration (Stripe stub) with trial and grace period
- `config/feature-flags.php` — Plan-based feature gating with rollout strategy

### API pack

Supports controls for API security and rate limiting.

**Middleware:**

- `AuthMiddleware` — API key and Bearer token authentication with public endpoint bypass
- `RateLimitMiddleware` — Per-client rate limiting with configurable windows
- `VersionMiddleware` — API version resolution from URL prefix or Accept header

**Other files:**

- `OpenApiSpec` — OpenAPI 3.1 specification generator stub with security scheme definitions

**Configuration:**

- `config/api.php` — API versioning, response format, pagination, and CORS settings
- `config/rate-limiting.php` — Tiered rate limiting (free, standard, premium) with rate limit headers
- `config/auth.php` — Authentication guards (API key, Bearer/JWT) with key storage configuration

## Creating custom packs

To create a custom control pack:

1. Create a directory under `resources/packs/` with your pack name:

```
resources/packs/my-pack/
  pack.json
  config/
  src/
  tests/
  docs/
    setup-guide.md
  CONTROLS.md
  NOT-CERTIFIED.md
```

2. Write a `pack.json` manifest with the required fields (`name`, `description`, `version`, `requiredPulsarVersion`).

3. Add template files in `src/` using `{{project_name}}`, `{{namespace}}`, and `{{project_slug}}` placeholders for project-specific values.

4. Add configuration stubs in `config/`.

5. Add test stubs in `tests/`.

6. Write `CONTROLS.md` using "supports controls for" language. Never claim compliance or certification.

7. Write `NOT-CERTIFIED.md` with a clear disclaimer about what the pack does and does not provide.

The pack will be automatically discovered by `pulsar new --list-packs` and available via `pulsar new --pack=my-pack`.

## Compliance disclaimer

Control packs provide **scaffolding that supports controls for** specific regulatory frameworks. They do **not**:

- Ensure compliance with any regulation
- Guarantee protection against data breaches or security incidents
- Substitute for professional security assessment, legal counsel, or regulatory audit
- Constitute legal advice of any kind

Before deploying to production in a regulated environment, you **must**:

- Engage qualified professionals for compliance assessment (QSA for PCI-DSS, HIPAA Security Risk Assessment, etc.)
- Implement all organizational, physical, and administrative controls required by applicable regulations
- Conduct penetration testing and security audits
- Establish incident response and breach notification procedures
- Obtain all required licenses and certifications for your jurisdiction
- Consult legal counsel regarding your specific regulatory obligations

The framework authors accept no liability for regulatory non-compliance arising from the use of control packs. See each pack's `NOT-CERTIFIED.md` for specific disclaimers.
