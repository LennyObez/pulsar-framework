# PRD — Pulsar Framework 1.0.0

> external audit finding **DOC-PRD-01**: every GA-claim must have a traceable
> entry naming the implementing files, tests, and CI gates. This document
> is the source of truth — every line item links to the code that satisfies
> it. Stub stage: the structure is complete, individual rows ratchet up to
> full evidence as remaining audit findings close.

## Mission and scope

Pulsar 1.0.0 is the first stable release of a PHP 8.5+ HMVC framework for
regulated, mission-critical domains. The contract for tagging GA is that
every claim made on `README.md`, `docs/`, and the project website is
backed by:

1. Working code at a known file:line (or in a named extension package).
2. An automated test in the CI suite that fails when the claim is violated.
3. A CI gate enforcing the test on every PR.

The PRD does NOT make a claim that the framework certifies any compliance
framework — the contract is "controls supported", not "framework certified".
See `README.md` "Compliance-ready controls" for the existing disclaimer.

## Top-level deliverables for 1.0.0

| Area | Deliverable | Evidence | Status |
|------|-------------|----------|--------|
| Core HTTP | PSR-7 request / response with hardened header + body validation | `src/Http/Message/{HeaderValidator,ContentDispositionBuilder,ServerRequest,Response}.php`; SEC-IN-01/02 + SEC-HTTP-01/02 | ✅ |
| Routing | HMVC with extension scoping and partial-trie matching | `src/Routing/Router.php`; F2.21 + ARCH-EXT family | ✅ |
| DI container | PSR-11 with autowiring + cycle detection + max-depth guard | `src/Container/**`; F2.20 | ✅ |
| Configuration | Typed DTOs + environment-aware loading | `src/Config/**` | ✅ |
| Sessions | Native + CSRF + step-up + fail-closed regenerate | `src/Security/Session/Session.php`; SEC-HTTP-02 | ✅ |
| Auth (TOTP + WebAuthn + OAuth2) | Unified `pulsar/auth` extension, homegrown per ADR-0032 | `extensions/auth/**`; SEC-AUTH-01/02 + SEC-OIDC-01/02 + ADR-0032 conformance vector suites | ⚠️ (gated on VECTORS-WA-01/OAUTH-01/JOSE-01 populated) |
| 2FA | Fail-closed rate limiter and replay guard | `src/Auth/TwoFactor/**`; SEC-2FA-01/02 | ✅ |
| Audit trail | HMAC chain + PII scrubbing + fail-closed deploy gate | `src/Security/Audit/AuditLogger.php`, `src/Deploy/Check/AuditLoggerReadinessCheck.php`; SEC-AUDIT-01/02 | ✅ |
| Crypto | libsodium-only (ADR-0006) + BLAKE2b keyed token indices | `src/Security/Crypto/**`, repository hash indices; SEC-CRYPTO-01/02 | ✅ |
| Trusted proxy | X-Forwarded-* gated by allowlisted source | `src/Http/TrustedProxy.php`, `src/Routing/Internal/ConfigDomainResolver.php`; SEC-IN-03 | ✅ |
| Subprocess sandbox | Env allowlist + array-form exec | `extensions/mcp-server/src/Internal/Subprocess/SubprocessRunner.php`; SEC-IPC-01 | ✅ |
| W3C WebAuthn conformance vector suite | Imported + green in CI | `extensions/auth/tests/Unit/WebAuthn/Ceremony/W3cConformanceVectorTest.php`; VECTORS-WA-01, ADR-0032 | ⚠️ (stub shipped, population is 1.0.0 GA blocker) |
| OAuth2/OIDC conformance vector suite | Imported + green in CI | `extensions/auth/tests/Unit/OAuth2/Rfc6749ConformanceTest.php`; VECTORS-OAUTH-01, ADR-0032 | ⚠️ (stub shipped, population is 1.0.0 GA blocker) |
| JOSE / JWT conformance vector suite | Imported + green in CI | `extensions/auth/tests/Unit/OAuth2/Oidc/RfcJoseConformanceTest.php`; VECTORS-JOSE-01, ADR-0032 | ⚠️ (stub shipped, population is 1.0.0 GA blocker) |
| External security audit | Memo from independent engineer | `docs/audit/`; F385.M4 | 🔜 (moved to 1.1.0 blocker per ADR-0032) |

## Compliance support claims

Every framework in `README.md` "Compliance-ready controls" must map back to
the `docs/security/asvs-l2-matrix.md` (ASVS L2 stub) and individual control
docs (`docs/compliance/<framework>.md`).

| Framework | Pulsar control delivery | Mapping doc |
|-----------|-------------------------|-------------|
| SOC 2 | Audit chain + RBAC + session management | `docs/compliance/soc2.md` (TBD ratchet) |
| HIPAA 2026 | Encryption, MFA, audit trails, access controls, incident hooks | `docs/compliance/hipaa.md` (TBD) |
| ISO 27001:2022 | Annex A.8 technical controls | `docs/compliance/iso27001.md` (TBD) |
| GDPR | Consent interfaces (GDPR-DEFAULT: requireConsent=true), audit | `docs/compliance/gdpr.md` (TBD) |
| PCI DSS v4.0.1 | BLAKE2b tokenization, key management, session hardening, audit | `docs/compliance/pci-dss.md` (TBD) |
| ISO 42001:2023 | AI governance extension | `docs/compliance/iso42001.md` (TBD) |
| NIS2 | Crypto, access controls, incident reporting, monitoring | `docs/compliance/nis2.md` (TBD) |
| eIDAS | Production gate refuses dev/test defaults (EIDAS-DEFAULT) | `docs/compliance/eidas.md` (TBD) |
| FHIR | Conformance levels (FHIR-IMPL: persistent repo work pending) | `docs/compliance/fhir.md` (TBD) |
| OWASP ASVS L2 | Per-clause matrix | `docs/security/asvs-l2-matrix.md` |

## Quality gates at GA

- `composer qa` includes: `cs:check`, `phpstan`, `psalm`, `boundary:check`,
  `test`, `security:lint` (Semgrep).
- `composer qa:full` adds `mutation` (Infection MSI ≥ 80, 90 for
  src/Auth/src/Security/src/Audit) and `test:coverage`.
- CI gate: line coverage ≥ 80% (ramps to 90 at GA), Infection MSI ≥ 80.
- PHPStan baseline + Psalm suppressions reduced to documented zero (or ≤ N
  with rationale).
- Every external audit finding in the internal findings register is either
  closed or has an explicit waiver in this PRD.
- External security audit memo (F385.M4) archived under `docs/audit/`.

## Out-of-scope for 1.0.0

- FHIR / MDR / eIDAS certifications themselves (Pulsar is a framework, not
  a certified product — operators integrate).
- Long-term-support guarantees beyond the published policy (set in the LTS
  doc — see ROADMAP).
- WebAuthn passkey UX flows beyond the spec-conformant ceremonies (the
  extension provides primitives, UX is downstream).

## Ratchet plan to GA

1. rc.12 (now) — clusters 1–4 closed (auth fork consolidation, HTTP/PSR-7
   hardening, audit/IPC fail-closed, batch quality fixes).
2. rc.13 — clusters 5–7 (perf precompilation, structural splits, compliance
   docs filled, ASVS YAML form).
3. rc.14 — WebAuthn library adoption + external audit memo land.
4. 1.0.0 GA — final security audit pass + release notes published.
