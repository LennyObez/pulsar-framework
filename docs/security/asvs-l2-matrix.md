# OWASP ASVS Level 2 — Pulsar control matrix

> external audit finding **ASVS-MATRIX**: every ASVS L2 clause maps to either a
> Pulsar control (with file:line and a test) or an explicit gap row.
> This is the initial stub — clauses without a Pulsar control reference
> are tracked as gap rows for the rc.x → 1.0.0 GA window.

## Status legend

- ✅ **Implemented**: control exists, file:line + test cited.
- ⚠️ **Partial**: control exists but the audit flagged coverage gaps.
- ❌ **Gap**: no framework-level control; downstream apps must add.
- 🔗 **External**: control belongs to operations or hosting, not the framework.

## V1 — Architecture, design and threat modeling

| Clause | Control | Status |
|--------|---------|--------|
| V1.1 — Secure SDLC | `docs/adr/*.md` (governance per ADR-0001) | ✅ |
| V1.2 — Authentication architecture | `src/Auth/**`, `extensions/auth/**` | ✅ |
| V1.4 — Access control architecture | `src/Auth/Authorization/**` | ✅ |
| V1.5 — Input / output validation | `src/Http/Message/HeaderValidator.php` (SEC-IN-01) | ✅ |
| V1.9 — Communications | TLS at hosting; framework enforces `Secure` cookies | 🔗 |

## V2 — Authentication

| Clause | Control | Status |
|--------|---------|--------|
| V2.1 — Password security | `src/Security/Crypto/PasswordHasher.php` (argon2id) | ✅ |
| V2.2 — Multi-factor | `src/Auth/TwoFactor/**` (SEC-2FA-01/02 fail-closed) | ✅ |
| V2.3 — Authenticator lifecycle | `extensions/auth/src/WebAuthn/**` | ⚠️ (SEC-WA-01 — ADR-0030 lib adoption pending) |
| V2.4 — Credential storage | `extensions/auth/src/OAuth2/Token/**` (BLAKE2b keyed, SEC-CRYPTO-01) | ✅ |
| V2.5 — Credential recovery | `src/Auth/TwoFactor/RecoveryCodeGenerator.php` | ✅ |
| V2.7 — Out-of-band | passkey / WebAuthn ceremonies | ⚠️ (same as V2.3) |
| V2.8 — One-time verifiers (TOTP) | `src/Auth/TwoFactor/TotpVerifier.php` (replay guard fail-closed) | ✅ |

## V3 — Session management

| Clause | Control | Status |
|--------|---------|--------|
| V3.2 — Session binding | `src/Security/Session/Session.php` (regenerate, SEC-HTTP-02) | ✅ |
| V3.3 — Session logout / termination | `Session::destroy()` | ✅ |
| V3.4 — Cookie attributes | `SessionConfig` (`Secure`, `HttpOnly`, `SameSite`) | ✅ |
| V3.5 — Token-based session | `extensions/auth/src/OAuth2/Token/**` | ✅ |

## V4 — Access control

| Clause | Control | Status |
|--------|---------|--------|
| V4.1 — General | `src/Auth/Authorization/GateInterface.php` | ✅ |
| V4.2 — Operation-level | `Gate::denies/allows` per-resource | ✅ |
| V4.3 — Other | `extensions/admin/**` step-up + role checks | ✅ |

## V5 — Validation, sanitization and encoding

| Clause | Control | Status |
|--------|---------|--------|
| V5.1 — Input validation | `src/Validation/**` | ✅ |
| V5.2 — Sanitization and sandboxing | `src/Security/Xss/SafeHtmlPolicy.php` | ✅ |
| V5.3 — Output encoding | Pulse template engine auto-escape | ✅ |
| V5.4 — Memory, string, and unmanaged code | not applicable to PHP runtime | 🔗 |
| V5.5 — Deserialization | `src/Serialization/SafeUnserialize.php` | ✅ |

## V6 — Stored cryptography

| Clause | Control | Status |
|--------|---------|--------|
| V6.1 — Data classification | `#[SensitiveParameter]` PHP 8.2+ attribute usage | ✅ |
| V6.2 — Algorithms | ADR-0006 (libsodium only) + Semgrep `pulsar.security.forbidden-sha256` | ✅ |
| V6.3 — Random values | `Random\Randomizer\Secure` everywhere | ✅ |
| V6.4 — Secret management | `src/Security/Crypto/KeyRingInterface.php` | ✅ |

## V7 — Error handling and logging

| Clause | Control | Status |
|--------|---------|--------|
| V7.1 — Log content | `src/Observability/Log/LogFormatter.php` (PII scrubbing) | ✅ |
| V7.2 — Log processing | structured JSON via JsonFormatter | ✅ |
| V7.3 — Log protection | `src/Security/Audit/AuditLogger.php` (HMAC chain) | ✅ |
| V7.4 — Error handling | `src/Core/Kernel.php` ExceptionHandler | ✅ |

## V8 — Data protection

| Clause | Control | Status |
|--------|---------|--------|
| V8.1 — General | `#[SensitiveParameter]`, observability scrubbing | ✅ |
| V8.2 — Client-side data | secure cookies, no LocalStorage tokens | ✅ |
| V8.3 — Sensitive private data | extension-specific (payments PCI) | ⚠️ |

## V9 — Communication

| Clause | Control | Status |
|--------|---------|--------|
| V9.1 — Client communications | HSTS via SecurityHeadersMiddleware | ✅ |
| V9.2 — Server communications | TLS verification on HttpClient | ✅ |

## V10 — Malicious code

| Clause | Control | Status |
|--------|---------|--------|
| V10.1 — Code integrity | `composer audit` + Roave/SecurityAdvisories | ✅ |
| V10.2 — Embedded malicious code | plugin signature (SEC-EXT-02, pending) | ⚠️ |
| V10.3 — Application integrity | deploy check signature (TOOL-DEP-01/02 pending) | ⚠️ |

## V11 — Business logic

| Clause | Control | Status |
|--------|---------|--------|
| V11.1 — Business logic security | application-layer, framework-neutral | 🔗 |

## V12 — Files and resources

| Clause | Control | Status |
|--------|---------|--------|
| V12.1 — File upload | `src/Http/Message/UploadedFile.php` + body cap (SEC-HTTP-01) | ✅ |
| V12.2 — File integrity | MIME sniffing + size limits | ✅ |
| V12.3 — File execution | uploads go to non-executable storage path | ✅ |
| V12.4 — File storage | `src/Storage/LocalStorageAdapter.php` (path traversal guarded) | ✅ |

## V13 — API and web service

| Clause | Control | Status |
|--------|---------|--------|
| V13.1 — Generic API | `src/Http/**`, PSR-7 validated headers (SEC-IN-01) | ✅ |
| V13.2 — REST | `extensions/oauth2/**` + `extensions/auth/**` | ✅ |
| V13.3 — SOAP | not supported | 🔗 |
| V13.4 — GraphQL | `extensions/graphql/**` | ⚠️ (depth/cost limits to verify) |

## V14 — Configuration

| Clause | Control | Status |
|--------|---------|--------|
| V14.1 — Build | reproducible composer + pnpm lockfiles | ✅ |
| V14.2 — Dependency | composer audit gate, Dependabot policy (SEC-SC-02 pending) | ⚠️ |
| V14.3 — Unintended security disclosure | `DebugModeCheck` deploy gate | ✅ |
| V14.4 — HTTP security headers | `SecurityHeadersMiddleware` (CSP, HSTS, X-Frame, X-Content-Type) | ✅ |
| V14.5 — HTTP request header validation | `HeaderValidator` (SEC-IN-01) | ✅ |

## Open gaps (active work)

- **V2.3 / V2.7** — WebAuthn lib adoption (SEC-WA-01 / ADR-0030).
- **V10.2 / V10.3** — Plugin signature (SEC-EXT-02), deploy check signature (TOOL-DEP-01/02).
- **V14.2** — Dependabot policy enforcement (SEC-SC-02).
- **V8.3** — PCI tokenization is implemented (SEC-CRYPTO-02 BLAKE2b keyed); the gap is downstream documentation, not framework code.

## Machine-readable form

This matrix is **stub markdown** to ensure the audit gap is closed. The
machine-readable YAML form (planned, also under ASVS-MATRIX in `.claude/findings.md`)
is a follow-up that wires each control row to its automated test + CI gate.
For now, treat this document as the authoritative manual mapping.
