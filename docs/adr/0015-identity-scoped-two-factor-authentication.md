# ADR-0015: Identity-Scoped Two-Factor Authentication

## Status

Accepted, except for the replay-guard key: **partially superseded by
[ADR-0038](0038-totp-replay-key-drops-purpose.md)**.

Decision driver 1 below ("Replay prevention must be identity-scoped and purpose-scoped")
and the `(identityId, purpose, timeStep)` key that followed from it are reversed. Keying on
purpose gave each purpose its own row, so one code was measurably accepted for `Login`,
`Setup` and `StepUp` in the same second — which ASVS 2.8.4 forbids. The key is now
`(user_id, time_step)`.

Everything else recorded here stands: the `identityId` parameter, encrypt-at-rest with
identity-bound AEAD, atomic recovery-code consumption, the audit events, and the step-up
middleware.

## Context

The existing 2FA subsystem (`src/Auth/TwoFactor/`) had several security gaps: the `TotpVerifier` hardcoded a 30-second period regardless of generator configuration; the replay guard keyed on `(identityId, code)` instead of `(identityId, purpose, timeStep)`, allowing cross-purpose replay; recovery codes used 32-bit entropy with no atomic consume contract; TOTP secrets were stored in plaintext; and no audit events were emitted for 2FA operations.

The `TwoFactorManagerInterface` lacked an `identityId` parameter on `verifyCode()` and `confirmSetup()`, making it impossible to enforce per-identity replay prevention or secret store lookups.

These gaps are unacceptable for regulated domains (banking, healthcare, legal) where Pulsar is deployed.

## Decision drivers

1. Replay prevention must be identity-scoped and purpose-scoped
2. Recovery code consumption must be atomic (race-safe)
3. TOTP secrets must be encrypted at rest with identity-bound AEAD
4. All 2FA operations must emit structured audit events
5. The framework must warn loudly when non-persistent stores are active in production

## Decision

Break `TwoFactorManagerInterface` to require `string $identityId` on `verifyCode()` and `confirmSetup()`. Redesign the replay guard to key on `(identityId, purpose, timeStep)` instead of `(identityId, code)`. Add `TotpSecretStoreInterface` for encrypt-at-rest with `identityId` as AEAD associated data. Add `RecoveryCodeStoreInterface` with atomic `consume()`. Emit audit events for all 10 2FA decision points. Add `TwoFactorRateLimiterInterface` as an optional integration point with extensible context. Add `StepUpMiddleware` scoped per identity. Add `AuthEventCollectorInterface` for Studio observability with allowlist-based metadata redaction.

Wire defaults in `AuthWiring`: in-memory stores for development with a `WARNING` log when active in production (suppressed by `two_factor.allowInMemory: true`).

## Alternatives considered

### Keep `verifyCode()` without `identityId`

Replay prevention without identity scoping would require the caller to manage identity context externally, creating a footgun where replay guards could be bypassed by omitting the identity. Rejected because the interface must enforce security invariants.

### Store TOTP secrets unhashed (symmetric encryption only)

TOTP secrets cannot be hashed (they must be recoverable for code verification). Symmetric encryption without identity binding (AAD) would allow ciphertext to be replayed under a different identity record if the database is compromised. Rejected in favor of AEAD with `identityId` as associated data.

### Emit audit events via a generic event dispatcher

A generic event system would not guarantee the structured format (actor, action, resource, outcome) required for compliance. The existing `AuditLogger` already provides this format with HMAC chain integrity. Rejected in favor of direct `AuditLogger` integration.

## Consequences

### Positive

- TOTP replay is prevented per identity, per purpose, per time step
- Recovery code races are eliminated by atomic consume
- TOTP secrets are encrypted at rest with identity binding
- All 2FA operations are auditable
- Production misconfiguration is detected at boot time

### Negative

- Breaking API change: `TwoFactorManagerInterface` gains required `identityId` parameter
- Callers must update all `verifyCode()` and `confirmSetup()` call sites

### Neutral

- In-memory stores remain the default for development; production requires explicit binding of persistent implementations

## Security impact

Addresses TOTP replay, recovery code race conditions, TOTP secret exposure at rest, cross-identity AEAD replay, and missing audit trail. See the "Appendix: security threat model" section of `docs/authentication.md` for the full threat model.

## Performance impact

None on the hot path. Replay guard lookups and recovery code store operations are bounded by the 2FA verification flow (not per-request). AEAD encryption/decryption uses libsodium primitives (sub-microsecond).

## Migration / rollback plan

**Adoption**: Update all `verifyCode()` and `confirmSetup()` call sites to pass `$identityId`. Bind persistent `TotpSecretStoreInterface`, `RecoveryCodeStoreInterface`, and `TotpReplayGuardInterface` implementations for production. The deprecated `verifyCodeWithSecret()` method provides a migration path for callers that manage secrets externally.

**Rollback**: Revert to pre-rc.8 `TwoFactorManagerInterface` signatures. The in-memory defaults ensure no data loss during rollback (production stores are app-provided).

## Links

- PR #31: feat(security): harden 2FA + add social SSO extension + auth security docs
- `docs/authentication.md` (appendix): Threat model and deployment guidance
- ADR-0006: libsodium-only crypto, master key derivation
- ADR-0008: HMAC-chained tamper-evident audit logging
- ADR-0014: Kernel service wiring decomposition
