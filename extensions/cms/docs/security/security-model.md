# CMS Security Model

This document describes the complete security model for Pulsar CMS, including roles and permissions, step-up authentication, two-factor authentication, Content Security Policy, safe HTML policy, upload security, and audit logging.

## Role-Based Access Control

Pulsar CMS defines a hierarchical role system with fine-grained permissions. Roles are registered with the framework's `RoleRegistryInterface` during extension boot.

### Roles

| Role                   | Description                        | Use Case                          |
| ---------------------- | ---------------------------------- | --------------------------------- |
| `cms.viewer`           | Read-only access to public content | Public visitors (no admin access) |
| `cms.contributor`      | Create and edit own content        | Blog contributors, guest authors  |
| `cms.reviewer`         | Review and approve content         | Editorial review team             |
| `cms.editor`           | Full content management            | Senior editors, content managers  |
| `cms.media_manager`    | Media upload and deletion          | Dedicated media team              |
| `cms.seo_manager`      | SEO tools and configuration        | SEO specialists                   |
| `cms.shop_manager`     | Commerce operations                | E-commerce team                   |
| `cms.analytics_viewer` | Search analytics access            | Marketing/analytics team          |
| `cms.admin`            | Full CMS administration            | Site administrators               |

### Permission Matrix

#### Content Permissions

| Permission                  | Viewer | Contributor | Reviewer | Editor | Admin |
| --------------------------- | ------ | ----------- | -------- | ------ | ----- |
| `cms.dashboard.view`        | --     | Yes         | Yes      | Yes    | Yes   |
| `cms.content.view`          | --     | Yes         | Yes      | Yes    | Yes   |
| `cms.content.create`        | --     | Yes         | Yes      | Yes    | Yes   |
| `cms.content.edit_own`      | --     | Yes         | Yes      | Yes    | Yes   |
| `cms.content.edit`          | --     | --          | --       | Yes    | Yes   |
| `cms.content.submit_review` | --     | Yes         | Yes      | Yes    | Yes   |
| `cms.content.approve`       | --     | --          | Yes      | Yes    | Yes   |
| `cms.content.publish`       | --     | --          | --       | Yes    | Yes   |
| `cms.content.archive`       | --     | --          | --       | Yes    | Yes   |
| `cms.content.restore`       | --     | --          | --       | Yes    | Yes   |
| `cms.content.delete`        | --     | --          | --       | Yes    | Yes   |
| `cms.content.force_unlock`  | --     | --          | --       | Yes    | Yes   |
| `cms.content.manage_fields` | --     | --          | --       | --     | Yes   |

#### Taxonomy & Navigation Permissions

| Permission            | Contributor | Editor | Admin |
| --------------------- | ----------- | ------ | ----- |
| `cms.taxonomy.view`   | Yes         | Yes    | Yes   |
| `cms.taxonomy.manage` | --          | Yes    | Yes   |
| `cms.menus.view`      | Yes         | Yes    | Yes   |
| `cms.menus.manage`    | --          | Yes    | Yes   |

#### Media Permissions

| Permission         | Contributor | Media Manager | Admin |
| ------------------ | ----------- | ------------- | ----- |
| `cms.media.view`   | Yes         | Yes           | Yes   |
| `cms.media.upload` | --          | Yes           | Yes   |
| `cms.media.delete` | --          | Yes           | Yes   |

#### Comment Permissions

| Permission              | Contributor | Editor | Admin |
| ----------------------- | ----------- | ------ | ----- |
| `cms.comments.view`     | Yes         | Yes    | Yes   |
| `cms.comments.moderate` | --          | Yes    | Yes   |

#### SEO Permissions

| Permission       | SEO Manager | Admin |
| ---------------- | ----------- | ----- |
| `cms.seo.view`   | Yes         | Yes   |
| `cms.seo.manage` | Yes         | Yes   |

#### Commerce Permissions

| Permission                  | Shop Manager | Admin |
| --------------------------- | ------------ | ----- |
| `cms.products.view`         | Yes          | Yes   |
| `cms.products.create`       | Yes          | Yes   |
| `cms.products.edit`         | Yes          | Yes   |
| `cms.products.delete`       | Yes          | Yes   |
| `cms.orders.view`           | Yes          | Yes   |
| `cms.orders.manage`         | Yes          | Yes   |
| `cms.orders.refund`         | Yes          | Yes   |
| `cms.orders.export`         | Yes          | Yes   |
| `cms.promotions.view`       | Yes          | Yes   |
| `cms.promotions.manage`     | Yes          | Yes   |
| `cms.invoices.view`         | Yes          | Yes   |
| `cms.invoices.download`     | Yes          | Yes   |
| `cms.digital_assets.manage` | Yes          | Yes   |

#### Analytics Permissions

| Permission                  | Analytics Viewer | Admin |
| --------------------------- | ---------------- | ----- |
| `cms.search.view_analytics` | Yes              | Yes   |

#### Administration Permissions (Admin Only)

| Permission             | Description                          |
| ---------------------- | ------------------------------------ |
| `cms.themes.view`      | View installed themes                |
| `cms.themes.install`   | Install new themes                   |
| `cms.themes.manage`    | Activate, deactivate, preview themes |
| `cms.themes.delete`    | Delete themes                        |
| `cms.plugins.view`     | View installed plugins               |
| `cms.plugins.install`  | Install new plugins                  |
| `cms.plugins.manage`   | Enable, disable, configure plugins   |
| `cms.plugins.delete`   | Delete plugins                       |
| `cms.settings.view`    | View CMS settings                    |
| `cms.settings.manage`  | Modify CMS settings                  |
| `cms.users.view`       | View CMS users                       |
| `cms.users.manage`     | Manage users, reset 2FA              |
| `cms.export`           | Export CMS data                      |
| `cms.import`           | Import CMS data                      |
| `cms.backup.create`    | Create backups                       |
| `cms.backup.restore`   | Restore from backups                 |
| `cms.livecss.view`     | View Live CSS editor                 |
| `cms.livecss.edit`     | Edit and save CSS overrides          |
| `cms.livecss.rollback` | Roll back CSS versions               |

## Step-Up Authentication

Step-up authentication provides an additional verification layer for sensitive operations within an already-authenticated session.

### How It Works

1. User performs a sensitive action (2FA management, settings change, etc.).
2. The system checks for a valid step-up token in the request attributes.
3. If no valid token exists, the user is prompted to re-authenticate.
4. After successful re-authentication, a step-up token is issued.
5. The token is valid for the configured TTL (default: 15 minutes).

### Configuration

```php
'security' => [
    'step_up_ttl_minutes' => 15,
],
```

### Protected Operations

All operations that require step-up authentication:

- 2FA enrollment, confirmation, and disabling
- Recovery code regeneration
- User 2FA reset
- Security settings changes

## Two-Factor Authentication

See the [2FA Setup Guide](../user/2fa-setup.md) for user-facing instructions.

### Technical Implementation

- **Algorithm**: HMAC-SHA1 TOTP per RFC 6238
- **Secret**: 160-bit random secret, Base32-encoded for display
- **Code format**: 6-digit, 30-second time step
- **Verification**: Allows +/- 1 time step for clock drift tolerance
- **QR encoding**: SVG-based QR code generation (no external dependencies)
- **Recovery codes**: 8 cryptographically random codes per generation

### Audit Trail

Every 2FA operation is logged. See [Audit Events Reference](audit-events.md) for the complete list.

## Content Security Policy (CSP)

Pulsar CMS enforces a strict Content Security Policy.

### Default Policy

```
default-src 'self';
script-src 'self';
style-src 'self' 'sha256-{live-css-hash}';
img-src 'self' https: data:;
font-src 'self';
connect-src 'self';
frame-ancestors 'none';
base-uri 'self';
form-action 'self';
```

### Live CSS Integration

The Live CSS system uses CSP hash-based allowlisting. Each saved CSS override produces a SHA-256 hash that is added to the `style-src` directive. This avoids the security weakness of `'unsafe-inline'`.

### External Fonts

When `live_css.allow_external_fonts` is `true`, the `font-src` directive is extended to allow the specified origins.

## Safe HTML Policy

All user-authored HTML is processed through the `SafeHtmlPolicy`, a 7-step sanitization pipeline.

### Sanitization Steps

1. **Input canonicalization**: UTF-8 conversion, null byte removal, BiDi control character stripping, CDATA marker removal
2. **DOM parsing**: Safe parsing with `LIBXML_NONET | LIBXML_NOERROR`
3. **Tree walk**: Bottom-up removal of disallowed elements (children are preserved)
4. **Attribute filtering**: Per-element attribute allowlists
5. **URL sanitization**: Scheme validation (http, https, mailto, data for images), anti-double-encoding
6. **Dangerous construct removal**: Event handlers (`on*`), `style` attributes, `data-*` attributes, `srcset`, `formaction`
7. **Final validation**: Regex scan for `javascript:`, `vbscript:`, `expression()`, `<script>`, `<svg>`, `<math>`

### Allowed Schemes

| Context     | Allowed Schemes                                                 |
| ----------- | --------------------------------------------------------------- |
| `<a href>`  | `http`, `https`, `mailto`, relative URLs                        |
| `<img src>` | `https`, relative `/media/` paths, `data:` (images under 32 KB) |

### Comment Sanitization

Comments use a strict subset: only `p`, `br`, `strong`, `em`, `a`, `code`, `blockquote`, `pre`.

### Bypass Detection

If the final regex scan detects dangerous patterns after sanitization, the entire input is escaped to plaintext and a security event is audit-logged.

## Upload Security

### File Validation

All uploads are validated by the `FileValidator`:

- MIME type validation using file content detection (not just extension)
- Extension allowlist enforcement
- File size limits
- Image dimension limits (width, height, total pixels)

### SVG Sanitization

SVG files are processed by the `SvgSanitizer`:

- Script element removal
- Event handler attribute removal
- External resource reference stripping
- Structural validation

### PDF Validation

PDF files are processed by the `PdfValidator`:

- Header verification (`%PDF-`)
- JavaScript detection and rejection
- Structural validation

### Filename Sanitization

The `FilenameSanitizer` processes all uploaded filenames:

- ASCII transliteration
- Special character replacement
- Lowercase normalization
- Unique suffix generation

### EXIF Stripping

By default, EXIF metadata is stripped from uploaded images to prevent PII leakage (GPS coordinates, camera identifiers, etc.).

## SSRF Protection

Outbound HTTP requests from the CMS (link health checks, oEmbed, media downloads) use the `SafeHttpClient` with SSRF protection.

### Blocked Networks

By default, the following IP ranges are blocked for outbound requests:

- `127.0.0.0/8` (loopback)
- `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` (RFC 1918 private)
- `169.254.0.0/16` (link-local)
- `0.0.0.0/8` (unspecified)
- `100.64.0.0/10` (carrier-grade NAT)
- `198.18.0.0/15` (benchmarking)
- `::1/128`, `fc00::/7`, `fe80::/10` (IPv6 equivalents)

### Request Limits

| Setting           | Default    | Description                 |
| ----------------- | ---------- | --------------------------- |
| Allowed ports     | 80, 443    | Only HTTP/HTTPS ports       |
| Max redirects     | 3          | Limits redirect chain depth |
| Connect timeout   | 5 seconds  | TCP connection timeout      |
| Total timeout     | 15 seconds | Full request timeout        |
| Max response size | 10 MB      | Response body size limit    |

## Client Fingerprinting

The `ClientFingerprintResolver` creates client fingerprints for rate limiting and anti-abuse:

- IP address resolution (with trusted proxy support)
- IPv6 subnet masking (default: /64) for privacy
- Cloudflare mode support (CF-Connecting-IP header)
- Configurable header names for reverse proxy setups

## Plugin Security Sandbox

Plugins operate within a restricted sandbox:

- **Scoped container**: Only declared-capability services are accessible
- **No direct database access**: Plugins interact through provided service interfaces
- **Hook isolation**: Hook execution has error boundaries and timeouts
- **Signature verification**: Optional Ed25519 signature enforcement for plugin archives
- **Integrity checks**: Boot-time file integrity verification

## Audit Logging

All security-relevant operations are recorded in the audit log. Each entry includes:

| Field      | Description                                                                     |
| ---------- | ------------------------------------------------------------------------------- |
| Event type | Category (SecurityEvent, Authentication, DataModification, ConfigurationChange) |
| Outcome    | Success, Failure, or Denied                                                     |
| Actor ID   | The user who performed the action (null for system events)                      |
| Action     | Machine-readable action identifier (e.g., `cms.2fa.enrollment_started`)         |
| Subject    | The affected resource (e.g., `user:uuid`, `content:uuid`)                       |
| Evidence   | Additional context fields                                                       |

See the [Audit Events Reference](audit-events.md) for the complete taxonomy.

## Next Steps

- [Threat Model](threat-model.md) - Attack surface analysis and mitigations
- [Audit Events Reference](audit-events.md) - Complete audit event taxonomy
- [Compliance Guide](compliance-guide.md) - GDPR and regulatory compliance
