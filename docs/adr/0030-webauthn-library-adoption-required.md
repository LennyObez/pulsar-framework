# ADR-0030: WebAuthn extension must use `web-auth/webauthn-lib`, not a homegrown implementation

## Status

**Superseded by [ADR-0032](0032-homegrown-auth-with-conformance-vectors-gate.md)
(2026-05-12).** The mandate to swap the WebAuthn extension onto
`web-auth/webauthn-lib` was retracted. The homegrown implementation stays;
1.0.0 GA gates instead on the W3C WebAuthn conformance vector suite landing
green in CI. External security audit moves from "1.0.0 blocker" to "1.1.0
blocker". See ADR-0032 for the full rationale.

The original decision history below is preserved for the record.

## Original status

Accepted (blocks 1.0.0 GA on the WebAuthn extension)

## Context

ADR-0025 (OAuth2/OIDC + WebAuthn library adapters) states explicitly that Pulsar's WebAuthn extension wraps `web-auth/webauthn-lib` ^5.0 — chosen because rolling a custom WebAuthn implementation is "a liability" given the protocol's complex state machine, multiple attestation formats, and known attack surface.

Audit finding **F385.7 / F385.9** identified that the as-shipped `pulsar/webauthn` extension contains a homegrown implementation:

- `composer.json` declares `web-auth/webauthn-lib: ^5.0` as a runtime dependency.
- No source file under `extensions/webauthn/src/` imports any `Webauthn\…` class from the library.
- Custom code under `Adapter/`, `Ceremony/`, `Attestation/`, `Authenticator/`, `PublicKey/` re-implements challenge generation, origin verification, RP ID hash verification, authenticator counter monotonicity, attestation chain verification, signature algorithm whitelisting, and clientDataJSON canonicalisation — every one of which has known correctness pitfalls in the W3C WebAuthn specification.

For a framework targeting banking / healthcare / legal domains where WebAuthn is the compliance-critical authentication path (PSD2 SCA, NIST 800-63 AAL2/AAL3), shipping a homegrown implementation without external audit is unacceptable.

## Decision drivers

1. **ADR-0025 is binding.** The earlier decision is "accepted" in the ADR registry; the implementation diverged silently.
2. **W3C WebAuthn correctness depends on subtle invariants.** Any bug in challenge CSPRNG, origin check, RP-ID hash, counter monotonicity, attestation verification, or `alg:none` filtering breaks the entire authentication security model.
3. **Audit cost.** External security audit of a homegrown WebAuthn implementation is significantly more expensive than auditing thin adapter code that wraps a battle-tested library.
4. **Operational support.** `web-auth/webauthn-lib` is maintained by Spomky-Labs (active FIDO Alliance contributor); homegrown code becomes an internal maintenance burden the framework team cannot match.

## Decision

### 1. The WebAuthn extension MUST wrap `web-auth/webauthn-lib`.

The library is already a `composer.json` requirement. The current `pulsar/webauthn` source must be refactored so that `WebAuthnServer`, `RegistrationCeremony`, and `AuthenticationCeremony` are thin adapters over the library's `PublicKeyCredentialCreationOptionsFactory`, `PublicKeyCredentialRequestOptionsFactory`, `AuthenticatorAttestationResponseValidator`, and `AuthenticatorAssertionResponseValidator` services.

Pulsar contracts (`WebAuthnServerInterface`, `RegistrationOptions`, `AuthenticationResult`, etc.) remain the public API; their implementations delegate to the library.

### 2. External security audit is REQUIRED before 1.0.0 GA tagging.

Even after the library swap, the extension's adapter layer (challenge persistence, RP configuration, credential repository implementations, attestation policy hooks) is in the trusted path of every authentication and registration ceremony. An independent security engineer — not the same author/reviewer who shipped the homegrown code — must audit:

- challenge issuance and binding to the active session,
- origin validation against the configured RP origin allowlist,
- credential storage and authenticator-counter monotonicity enforcement,
- attestation verification policy (which formats are accepted, which are rejected),
- key material lifecycle through `KeyRingInterface` (ADR-0025 driver 4).

The audit deliverable is a memo signed by the external engineer, archived under `docs/audit/` with the ADR cross-reference.

### 3. W3C conformance vectors MUST be in CI.

The corpus at `https://github.com/web-auth/webauthn-test-vectors` covers registration and authentication ceremonies across attestation formats. The WebAuthn extension test suite must run that corpus on every CI run; failures block merge.

### 4. The extension MUST NOT be shipped under the `1.0.0` tag in its current form.

Until the library swap and external audit are complete, the WebAuthn extension is held back from the GA milestone. Options:

- (A) Block the GA tag until WebAuthn is remediated.
- (B) Mark the WebAuthn extension as `experimental` in the `1.0.0` release and cut a separate semver line for it (`pulsar/webauthn 0.x` parallel to `pulsar/* 1.0.0`).
- (C) Remove the extension from the GA package entirely; reintroduce in `1.1.0`.

The release manager picks one; this ADR records that staying with the current homegrown code AND tagging GA is not an option.

## Consequences

### Positive

- Closes audit finding F385.9.
- Aligns the implementation with the ADR-0025 decision the maintainers already accepted.
- Reduces audit scope and ongoing maintenance cost.
- Makes the WebAuthn extension's security posture defensible to regulators.

### Negative

- Refactor cost: the homegrown code under `Adapter/`, `Ceremony/`, `Attestation/`, `Authenticator/`, `PublicKey/` is a non-trivial rewrite. Estimated 2–4 sprint weeks for a senior PHP engineer.
- Possible API surface adjustments where Pulsar contracts diverge from the library's natural shape.

## Tracking

- Audit finding: **F385.9** in `.claude/findings.md`.
- Cross-reference: ADR-0025 §"WebAuthn: `web-auth/webauthn-lib`".
- Owner: WebAuthn extension maintainer (assignment pending).
- Blocking: 1.0.0 GA tag.

## Notes for implementers

The library's natural extension points are:

- `Webauthn\PublicKeyCredentialSource` — replaces the bespoke `Authenticator/StoredCredential` value object.
- `Webauthn\PublicKeyCredentialSourceRepository` — replaces `Contract\AuthenticatorRepositoryInterface`. Keep the Pulsar interface as the public adapter port and have the implementation forward to the library port.
- `Webauthn\AuthenticatorAttestationResponseValidator` — replaces `Ceremony\RegistrationCeremony`'s attestation logic.
- `Webauthn\AuthenticatorAssertionResponseValidator` — replaces `Ceremony\AuthenticationCeremony`'s signature verification.
- Challenge persistence stays a Pulsar responsibility (Redis/DB/session-backed) but must be HMAC-bound to the active session via `KeyRingInterface`-derived signing keys.

Do not import `Webauthn\…` symbols outside the `pulsar/webauthn` extension. The extension's own contracts remain the framework-public surface; the library is an internal implementation detail (ADR-0025 driver 3, "Swappability").
