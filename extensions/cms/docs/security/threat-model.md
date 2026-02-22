# CMS Threat Model

This document analyzes the attack surface of Pulsar CMS, maps threats to OWASP Top 10 categories, and documents the mitigations implemented for each threat vector.

## Attack Surface Overview

### Entry Points

| Surface               | Description                               | Authentication Required |
| --------------------- | ----------------------------------------- | ----------------------- |
| Public content routes | `/{locale}/{path}` rendering              | No                      |
| Comment submission    | Public comment forms                      | Configurable            |
| Checkout flow         | `/{locale}/checkout`                      | No                      |
| Digital download      | `/download/{token}`                       | Token-based             |
| Payment webhook       | `/webhooks/cms-payment`                   | Signature-based         |
| Admin panel           | `/admin/cms/*`                            | Yes                     |
| 2FA endpoints         | `/admin/cms/2fa/*`                        | Yes + step-up           |
| Import/export         | `/admin/cms/import`, `/admin/cms/export`  | Yes (Admin)             |
| Theme/plugin upload   | `/admin/cms/themes`, `/admin/cms/plugins` | Yes (Admin)             |

### Data Assets

| Asset             | Classification | Impact if Compromised              |
| ----------------- | -------------- | ---------------------------------- |
| User credentials  | Confidential   | Account takeover                   |
| TOTP secrets      | Confidential   | 2FA bypass                         |
| Payment data      | Confidential   | Financial fraud                    |
| Customer PII      | Personal       | Privacy breach, regulatory penalty |
| Content database  | Internal       | Defacement, data loss              |
| Configuration     | Internal       | Privilege escalation               |
| Theme/plugin code | Internal       | Remote code execution              |

## OWASP Top 10 Threat Analysis

### A01: Broken Access Control

**Threats:**

- Unauthorized content modification
- Privilege escalation from Contributor to Editor/Admin
- Accessing admin routes without authentication
- Bypassing editorial workflow

**Mitigations:**

- Fine-grained permission system with 9 roles and 40+ permissions
- Every controller action checks permissions via `GateInterface`
- Editorial workflow enforces state machine transitions (cannot skip InReview)
- Content locking prevents concurrent editing conflicts
- Step-up authentication for sensitive operations (2FA, settings)
- Content edit restricted by `edit_own` vs `edit` permissions

### A02: Cryptographic Failures

**Threats:**

- Weak TOTP secret generation
- Predictable download tokens
- Inadequate password hashing
- Theme/plugin signature bypass

**Mitigations:**

- TOTP secrets: 160-bit cryptographically random values
- Download tokens: UUIDv7 with cryptographic randomness
- Ed25519 signatures for theme and plugin verification
- Recovery codes: cryptographically random generation
- All secrets transmitted over HTTPS only

### A03: Injection

**Threats:**

- Cross-Site Scripting (XSS) via content body
- XSS via comment bodies
- XSS via SVG uploads
- SQL injection via search or filter parameters
- HTML injection via import files

**Mitigations:**

- 7-step SafeHtmlPolicy sanitization for all user-authored HTML
- Strict comment HTML subset (only 8 elements allowed)
- SVG sanitization removes all script elements and event handlers
- Parameterized database queries throughout (no string concatenation)
- Import data validation before database insertion
- Content Security Policy prevents inline script execution
- BiDi control character stripping prevents text manipulation attacks

### A04: Insecure Design

**Threats:**

- Missing rate limits on authentication
- Unbounded resource consumption
- Predictable business logic flows

**Mitigations:**

- Comment rate limiting (per-minute and per-hour)
- Honeypot bot detection
- Upload size limits and decompression bomb prevention
- Page hierarchy depth limits (cycle detection)
- Maximum CSS length enforcement
- Theme archive file count limits
- Single-flight cache stampede protection

### A05: Security Misconfiguration

**Threats:**

- Default configuration exposing sensitive data
- CORS misconfiguration
- Verbose error messages

**Mitigations:**

- Secure defaults in all configuration DTOs
- SSRF protection enabled by default
- Signed themes required by default
- Integrity checks on boot enabled by default
- EXIF stripping enabled by default
- Auto-approve comments disabled by default
- Admin routes not accessible without authentication

### A06: Vulnerable and Outdated Components

**Threats:**

- Malicious themes or plugins
- Supply chain compromise of CMS extensions

**Mitigations:**

- Ed25519 signature verification for themes and plugins
- Provenance tracking with tamper detection
- Boot-time integrity verification
- Plugin capability sandboxing
- Scoped container prevents unauthorized service access
- Manifest validation with version constraint checking

### A07: Identification and Authentication Failures

**Threats:**

- Brute-force TOTP guessing
- Session fixation after 2FA enrollment
- Recovery code enumeration

**Mitigations:**

- TOTP verification with time-step tracking (prevents replay)
- Step-up authentication with configurable TTL
- Recovery codes are single-use and invalidated after use
- Failed 2FA attempts are audit-logged
- Mandatory reason for 2FA disable (minimum 10 characters)

### A08: Software and Data Integrity Failures

**Threats:**

- Tampered theme archives
- Modified plugin code on disk
- Import of malicious site definitions

**Mitigations:**

- Theme archive signature verification (Ed25519)
- Plugin archive signature verification (Ed25519)
- Boot-time file integrity checks
- SafeArchiveExtractor prevents path traversal and symlink attacks
- Import validation with dry-run preview before execution
- Site definition schema validation

### A09: Security Logging and Monitoring Failures

**Threats:**

- Security events going undetected
- Insufficient audit trail for compliance
- Missing evidence for forensic analysis

**Mitigations:**

- Comprehensive audit logging for all security events
- Event types: SecurityEvent, Authentication, DataModification, ConfigurationChange
- Each entry includes actor, action, subject, outcome, and evidence
- Evidence hash fields for tamper-proof audit records
- Comment moderation decisions are immutable and logged
- 2FA lifecycle fully logged (enrollment, verification, disable)
- Settings changes logged with before/after values

### A10: Server-Side Request Forgery (SSRF)

**Threats:**

- Link health checker accessing internal services
- oEmbed fetching from internal networks
- Import media download from internal networks
- Theme/plugin fetching from restricted URLs

**Mitigations:**

- SafeHttpClient with comprehensive SSRF protection
- Blocked IP ranges: RFC 1918, loopback, link-local, carrier-grade NAT
- Allowed outbound ports restricted to 80 and 443
- Redirect chain depth limit (max 3)
- Connection and total timeouts
- Response size limit (10 MB)
- DNS rebinding prevention through IP validation after resolution

## Upload Attack Vectors

### Image Decompression Bombs

**Threat:** Uploading a small compressed image that expands to consume all available memory.

**Mitigations:**

- Maximum image dimensions: 16,384 x 16,384 pixels
- Maximum total pixel count: 100,000,000
- File size limit: 10 MB
- Processing performed with bounded memory allocation

### Polyglot Files

**Threat:** Files that are valid in multiple formats (e.g., a JPEG that is also valid HTML).

**Mitigations:**

- MIME type validation using file content detection, not extension
- Content-Type and Content-Disposition headers set correctly on delivery
- X-Content-Type-Options: nosniff header
- SVG served with image/svg+xml MIME type, not text/html

### SVG Script Injection

**Threat:** SVG files containing `<script>` elements or event handlers.

**Mitigations:**

- SvgSanitizer removes all script elements
- Event handler attributes (onclick, onload, etc.) are stripped
- External resource references are removed
- Inline styles that could contain expressions are stripped

### PDF JavaScript

**Threat:** PDF files with embedded JavaScript for drive-by attacks.

**Mitigations:**

- PdfValidator detects and rejects PDFs containing JavaScript
- PDF structural validation
- File header verification

## Theme/Plugin Supply Chain Risks

### Malicious Code in Themes

**Threat:** A theme containing backdoor code or data exfiltration.

**Mitigations:**

- Ed25519 signature verification (when `require_signed_themes` is true)
- Trusted public key whitelist
- Boot-time integrity verification
- Safe mode fallback if theme causes errors
- Archive extraction with path traversal prevention

### Malicious Code in Plugins

**Threat:** A plugin with unauthorized capabilities or data access.

**Mitigations:**

- Capability declaration and enforcement
- Scoped container proxy limits service access
- Hook execution engine with error boundaries
- Ed25519 signature verification (when `require_signed_plugins` is true)
- Boot-time integrity verification
- Manifest validation including dependency checks

### Compromised Update

**Threat:** An attacker intercepting theme/plugin updates to inject malicious code.

**Mitigations:**

- Archives must pass signature verification against trusted keys
- The trusted public key set is stored in server configuration (not modifiable via admin panel)
- Integrity checks detect file modifications between installations

## Commerce-Specific Threats

### Payment Bypass

**Threat:** Completing a checkout without valid payment.

**Mitigations:**

- Order state machine enforces payment confirmation before fulfillment
- Payment status verified through gateway webhooks (server-to-server)
- Cart validation before checkout processing
- Webhook authentication through signature verification

### Price Manipulation

**Threat:** Modifying prices or coupon values client-side.

**Mitigations:**

- All price calculations performed server-side
- Product prices read from database, not client input
- Coupon validation against database records
- Tax calculation on the server

### Digital Asset Theft

**Threat:** Unauthorized access to paid digital downloads.

**Mitigations:**

- Token-based download URLs with expiration
- Maximum download count per purchase
- Token validation on every download request
- Tokens are non-guessable (UUIDv7)

## Recommendations for Operators

1. **Enable signed themes and plugins** in production
2. **Enable editorial workflow** for content governance
3. **Enable event sourcing** for complete audit trails
4. **Configure trusted proxy settings** for accurate IP resolution
5. **Set restrictive CORS policies** at the reverse proxy level
6. **Monitor audit logs** for security events and failed authentications
7. **Require 2FA** for all administrative accounts
8. **Regular backup schedule** with tested restore procedures
9. **Keep Pulsar and PHP updated** for security patches
10. **Review plugin capabilities** before enabling

## Next Steps

- [Security Model](security-model.md) -- Detailed security controls
- [Audit Events Reference](audit-events.md) -- Monitoring security events
- [Compliance Guide](compliance-guide.md) -- Regulatory compliance
