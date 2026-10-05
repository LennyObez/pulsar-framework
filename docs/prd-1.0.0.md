# PRD — Pulsar Framework 1.0.0

> Every GA claim must have a traceable entry naming the implementing files,
> tests, and CI gates. This document is the source of truth — every line
> item links to the code that satisfies it. The structure is complete;
> individual rows ratchet up to full evidence as the work behind them lands.

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

| Area                                  | Deliverable                                                     | Evidence                                                                                  | Status                                                |
| ------------------------------------- | --------------------------------------------------------------- | ----------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| Core HTTP                             | PSR-7 request / response with hardened header + body validation | `src/Http/Message/{HeaderValidator,ContentDispositionBuilder,ServerRequest,Response}.php` | ✅                                                    |
| Routing                               | HMVC with extension scoping and partial-trie matching           | `src/Routing/Router.php`                                                                  | ✅                                                    |
| DI container                          | PSR-11 with autowiring + cycle detection + max-depth guard      | `src/Container/**`                                                                        | ✅                                                    |
| Configuration                         | Typed DTOs + environment-aware loading                          | `src/Config/**`                                                                           | ✅                                                    |
| Sessions                              | Native + CSRF + step-up + fail-closed regenerate                | `src/Security/Session/Session.php`                                                        | ✅                                                    |
| Auth (TOTP + WebAuthn + OAuth2)       | Unified `pulsar/auth` extension, homegrown per ADR-0032         | `extensions/auth/**`; ADR-0032 conformance vector suites                                  | ⚠️ (gated on the three vector suites being populated) |
| 2FA                                   | Fail-closed rate limiter and replay guard                       | `src/Auth/TwoFactor/**`                                                                   | ✅                                                    |
| Audit trail                           | HMAC chain + PII scrubbing + fail-closed deploy gate            | `src/Security/Audit/AuditLogger.php`, `src/Deploy/Check/AuditLoggerReadinessCheck.php`    | ✅                                                    |
| Crypto                                | libsodium-only (ADR-0006) + BLAKE2b keyed token indices         | `src/Security/Crypto/**`, repository hash indices                                         | ✅                                                    |
| Trusted proxy                         | X-Forwarded-\* gated by allowlisted source                      | `src/Http/TrustedProxy.php`, `src/Routing/Internal/ConfigDomainResolver.php`              | ✅                                                    |
| Subprocess sandbox                    | Env allowlist + array-form exec                                 | `extensions/mcp-server/src/Internal/Subprocess/SubprocessRunner.php`                      | ✅                                                    |
| W3C WebAuthn conformance vector suite | Imported + green in CI                                          | `extensions/auth/tests/Unit/WebAuthn/Ceremony/W3cConformanceVectorTest.php`; ADR-0032     | ⚠️ (stub shipped, population is 1.0.0 GA blocker)     |
| OAuth2/OIDC conformance vector suite  | Imported + green in CI                                          | `extensions/auth/tests/Unit/OAuth2/Rfc6749ConformanceTest.php`; ADR-0032                  | ⚠️ (stub shipped, population is 1.0.0 GA blocker)     |
| JOSE / JWT conformance vector suite   | Imported + green in CI                                          | `extensions/auth/tests/Unit/OAuth2/Oidc/RfcJoseConformanceTest.php`; ADR-0032             | ⚠️ (stub shipped, population is 1.0.0 GA blocker)     |
| External security audit               | Memo from independent engineer                                  | `docs/security/`                                                                          | 🔜 (moved to 1.1.0 blocker per ADR-0032)              |

## Compliance support claims

Every framework in `README.md` "Compliance-ready controls" must map back to something a
reader can open.

An earlier revision of this section sent them to `docs/compliance/<framework>.md` — nine
paths under a directory that has never existed, each labelled `(TBD)`. A promise of a
document is not a mapping, and a table of nine of them reads as a documentation set rather
than as its absence. The pointer is withdrawn rather than fulfilled: the mapping already
exists in a form that cannot go stale the way a hand-written page can, because it is code
the compliance report executes.

Each framework's clause-to-control mapping is a class under `src/Compliance/Frameworks/`
(or the extension that owns it), read by `pulsar compliance:report`. Prose for the
capability groups behind them is [Compliance](compliance.md); the cross-framework common
control set is [Compliance CCF](compliance-ccf.md).

| Framework      | Pulsar control delivery                                        | Mapping                                                         |
| -------------- | -------------------------------------------------------------- | --------------------------------------------------------------- |
| SOC 2          | Audit chain + RBAC + session management                        | `src/Compliance/Frameworks/Soc2Mapping.php`                     |
| HIPAA 2026     | Encryption, MFA, audit trails, access controls, incident hooks | `src/Compliance/Frameworks/HipaaMapping.php`                    |
| ISO 27001:2022 | Annex A.8 technical controls                                   | `src/Compliance/Frameworks/Iso27001Mapping.php`                 |
| GDPR           | Consent interfaces (GDPR-DEFAULT: requireConsent=true), audit  | `src/Compliance/Frameworks/GdprMapping.php`                     |
| PCI DSS v4.0.1 | BLAKE2b tokenization, key management, session hardening, audit | `src/Compliance/Frameworks/PciDssMapping.php`                   |
| ISO 42001:2023 | AI governance extension                                        | `src/Compliance/Frameworks/Iso42001Mapping.php`                 |
| NIS2           | Crypto, access controls, incident reporting, monitoring        | `src/Compliance/Frameworks/Nis2Mapping.php`                     |
| eIDAS          | Production gate refuses dev/test defaults (EIDAS-DEFAULT)      | `src/Compliance/Frameworks/EidasMapping.php`                    |
| FHIR           | Conformance levels (FHIR-IMPL: persistent repo work pending)   | `src/Compliance/Frameworks/Hl7FhirMapping.php`                  |
| OWASP ASVS L2  | Per-clause matrix                                              | [`docs/security/asvs-l2-matrix.md`](security/asvs-l2-matrix.md) |

A mapping names controls; it does not report on them. What a given deployment achieves is
whatever `pulsar compliance:report` observes there — [ADR-0045](adr/0045-a-control-status-is-observed-not-written.md)
is why no status on this page, or any page, is allowed to stand in for that run.

## Quality gates at GA

- `composer qa` includes: `qa:parity`, `version:check`, `autoload:check`,
  `cs:check`, `phpstan`, `psalm`, `boundary:check`, `class-shape`,
  `security:malicious`, `test`, `compliance:check`, `security:lint` (Semgrep,
  gating at WARNING against `tools/security/semgrep-baseline.json`).
- `qa:parity` is what keeps that list honest: it fails the build when
  `.github/workflows/ci.yml` does not run every entry of `qa`. CI may enforce
  more, never less.
- `composer qa:full` adds `mutation` and `test:coverage`. Infection is scoped to
  src/Auth, src/Security and src/Audit for the memory reasons ADR-0042 measures,
  and enforces covered MSI at 90.
- CI gate: line coverage ≥ 80% (ramps to 90 at GA).
- CI gate: Infection covered MSI ≥ 90 over that scope. Plain MSI is reported and
  not enforced, and `tools/ci/assert-mutation-thresholds.php` fails the build on
  any document that claims otherwise.
- PHPStan baseline + Psalm suppressions reduced to documented zero (or ≤ N
  with rationale).
- Every open security review item is either closed or carries an explicit,
  documented waiver in this PRD.
- External security audit memo archived under `docs/security/`. (It was first filed
  under an audit directory that .gitignore excludes, so nothing filed there would ever
  have reached a reader of this repository.)

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
