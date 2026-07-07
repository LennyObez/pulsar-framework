# ADR-0032: Homegrown OAuth2/OIDC/WebAuthn/JOSE with mandatory conformance vector gate

## Status

Accepted. **Supersedes ADR-0025** (OAuth2/OIDC/WebAuthn library adapters) and
**ADR-0030** (WebAuthn library adoption required). The earlier decisions to
wrap `league/oauth2-server`, `web-auth/webauthn-lib`, and `web-token/jwt-framework`
are retracted.

## Context

ADR-0025 (2026-xx-xx) and ADR-0030 (2026-xx-xx) prescribed adopting three
external libraries for the OAuth2 server, WebAuthn extension, and JOSE / JWT
layer. The decision drivers were (1) regulatory pressure ("regulators consider
homegrown crypto as a red flag") and (2) audit cost reduction.

Re-examination during the external audit cycle (2026-05-12) showed:

1. **The regulatory argument was an overstatement.** PCI-DSS, HIPAA, eIDAS,
   ISO 27001, NIST 800-53 SC-13 do not require any specific library — they
   require algorithm choice (AES-GCM / SHA-256+ / Argon2id), evidence of
   conformance testing, threat-model documentation, and an audit memo from
   an independent engineer. Homegrown code with these six elements passes
   audit without friction.

2. **The audit cost argument is real but bounded.** Auditing 10–17k LOC of
   homegrown OAuth2/WebAuthn/JOSE costs ~10–20 day-engineer vs ~2 days for
   thin adapters — roughly +15–30k€ per major release. This is a budget
   line item, not a structural blocker.

3. **The framework already has the foundations of a conformance-driven
   approach.** The F385.18 commit (May 2026) imported the RFC 8949 Appendix
   A CBOR vectors into the WebAuthn extension and the run exposed a real
   bug in `unpack('n', …)` parsing. The pattern works.

4. **Adopting external libraries trades protocol-correctness risk for
   supply-chain risk.** Both are real; neither dominates the other. With
   a one-maintainer framework, dependency velocity and lock-in are real
   constraints.

5. **The "uncompromising excellence" mandate** (NASA / seL4 / SQLite
   inspiration) is more consistent with deep homegrown ownership backed by
   exhaustive conformance testing than with thin wrappers over moving
   third-party code.

## Decision

### 1. The current homegrown implementations stay in place.

`extensions/auth/src/OAuth2/`, `extensions/auth/src/WebAuthn/`, and the JOSE
primitives within them remain the canonical Pulsar OAuth2/OIDC/WebAuthn/JOSE
implementation. composer.json is NOT modified to require
`league/oauth2-server`, `web-auth/webauthn-lib`, or `web-token/jwt-framework`.

### 2. Conformance vector test suites are MANDATORY before 1.0.0 GA.

Three test corpora must run as part of `composer test` and the CI quality
gate before the GA tag can be cut:

1. **W3C WebAuthn conformance vectors**
   - Source: <https://github.com/web-auth/webauthn-test-vectors> + W3C
     WebAuthn Level 2/3 test cases.
   - Coverage: registration ceremony × 7 attestation formats (none, packed
     full + self, fido-u2f, android-key, android-safetynet, apple, tpm) +
     authentication ceremony × counter monotonicity + resident credential.
   - Target file: `extensions/auth/tests/Unit/WebAuthn/Ceremony/
W3cConformanceVectorTest.php` (scaffold ships in the ADR-accepting
     commit; population is a multi-PR follow-up).

2. **OAuth2 / OIDC conformance vectors**
   - Source: OpenID Foundation Self-Certification suite + RFC 6749 §A
     examples + RFC 7636 PKCE example + RFC 7662 introspection examples +
     RFC 8176 AMR/ACR examples.
   - Coverage: authorization code grant + PKCE S256 + refresh rotation +
     mix-up attack defense + redirect URI strict match + AMR/ACR mapping.
   - Target file: `extensions/auth/tests/Unit/OAuth2/Rfc6749ConformanceTest.php`.

3. **JOSE / JWT conformance vectors**
   - Source: RFC 7515 §A (JWS examples) + RFC 7517 §A (JWK examples) + RFC
     7518 §A (algorithm examples) + the known JWT attack vectors corpus
     (alg:none, RS256→HS256 key confusion, kid traversal, …).
   - Coverage: signing, verification, alg whitelist, JWKS publication,
     attack defense.
   - Target file: `extensions/auth/tests/Unit/OAuth2/Oidc/RfcJoseConformanceTest.php`.

### 3. External security audit is REQUIRED before 1.1.0, not 1.0.0.

The 1.0.0 GA tag may be cut with the conformance vector suite in place and
green. The external audit (commissioned to Cure53 / Trail of Bits / NCC
Group / equivalent) is moved to the 1.1.0 milestone. Rationale: shipping
1.0.0 with comprehensive in-house conformance evidence is defensible;
delaying GA until external audit closes adds 3-6 months for marginal
incremental risk reduction.

Findings from the 1.1.0 audit must be remediated before the 1.1.0 tag.

### 4. ADR-0025 and ADR-0030 are superseded.

`docs/adr/0025-oauth2-oidc-webauthn-library-adapters.md` and
`docs/adr/0030-webauthn-library-adoption-required.md` are updated to
reference this ADR as their successor. They are NOT deleted — they
document the decision history.

## Consequences

### Positive

- Preserves the framework's "uncompromising" identity and pride of
  authorship in security-critical code.
- Eliminates 3 new supply-chain dependencies and their CVE-tracking
  burden.
- Forces measurable evidence (vector suite green) instead of "we use lib X
  so we're fine" as the GA criterion.
- Defers the ~30k€ external audit cost from 1.0.0 to 1.1.0; gives time
  to find a remediation budget if findings come back significant.

### Negative

- The ~25-30 day-engineer cost of importing and maintaining the three
  vector corpora is now Pulsar's responsibility, not a library
  maintainer's.
- A library CVE that would have been patched by a `composer update` is now
  Pulsar's to track and patch internally for OAuth2/WebAuthn/JOSE specs.
- Auditor effort at every release stays at ~10–20 day-engineer for the
  custom code (worth budgeting ~30k€ per major release).
- If the 1.1.0 external audit returns findings that fundamentally
  contradict an implementation choice, remediation could be expensive.

### Neutral

- The Pulsar contracts (`WebAuthnServerInterface`, `AuthorizationServerInterface`,
  `JwtSignerInterface`, etc.) remain stable. The conformance evidence sits
  behind the interfaces; library adoption or homegrown ownership is an
  implementation detail.

## Tracking

- Closes audit finding **SEC-WA-01** (revoked, no longer applicable in
  the original "MUST swap library" form).
- Closes audit finding **SEC-SC-01** (revoked, no longer applicable).
- New finding **VECTORS-WA-01**: W3C WebAuthn conformance suite must run
  green in CI before 1.0.0 GA.
- New finding **VECTORS-OAUTH-01**: OAuth2 / OIDC conformance suite must
  run green in CI before 1.0.0 GA.
- New finding **VECTORS-JOSE-01**: JOSE / JWT conformance suite must run
  green in CI before 1.0.0 GA.
- **F385.M4** moves from "1.0.0 blocker" to "1.1.0 blocker": external
  security audit required before 1.1.0 tag.

## Cross-references

- ADR-0001 (governance) — this ADR follows the standard supersede flow.
- ADR-0006 (libsodium-only crypto policy) — still authoritative for the
  primitives.
- `docs/prd-1.0.0.md` — updated to reflect the new GA criteria.
- `ROADMAP.md` — the 1.0.0 milestone is rewritten to reflect this change.

## Date

2026-05-12.
