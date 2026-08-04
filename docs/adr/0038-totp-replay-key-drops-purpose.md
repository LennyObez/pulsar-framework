# ADR-0038: A TOTP code is redeemable once, not once per purpose

## Status

Accepted. **Partially supersedes [ADR-0015](0015-identity-scoped-two-factor-authentication.md)**
— specifically its first decision driver and the replay-guard key that followed from it.
Everything else ADR-0015 decided (the `identityId` parameter, encrypt-at-rest with
identity-bound AEAD, atomic recovery-code consumption, the audit events, the step-up
middleware) stands unchanged.

## Context

ADR-0015 keyed the TOTP replay guard on `(identityId, purpose, timeStep)`, replacing an
earlier key of `(identityId, code)`. Its stated reason was that the old key was "allowing
cross-purpose replay".

That reading is backwards, and the direction matters because it is what the fix reverses.
A key of `(identityId, code)` records the code itself, so a code redeemed for `Login`
collides with the same code presented for `Setup` — the old key *prevented* cross-purpose
reuse. Adding `purpose` to the key is what created it: each purpose gets its own row, so
the same code is accepted once per purpose.

Measured on the shipped schema: **a single TOTP code was accepted for `Login`, `Setup` and
`StepUp` within the same second.** ADR-0015's own consequence — "TOTP replay is prevented
per identity, per purpose, per time step" — is an accurate description of what it built
and an inaccurate description of replay prevention.

ASVS 4.0.3 **2.8.4** requires that a time-based OTP be usable **once**, within its validity
period. Not once per purpose, per channel, or per endpoint. A verifier that accepts the
same code three times in one time step does not satisfy it, however the three uses are
labelled.

The old `(identityId, code)` key was not the right answer either. Six-digit codes collide:
across a long-lived account the same code recurs, and keying on the code blocks a
legitimate later code that happens to repeat an earlier one. The time step is what
identifies the redemption; the code is what proves it.

A second defect travelled with the first. The pruning index covered `used_at` alone and
the `DELETE` that used it carried no `user_id` predicate, so unrelated traffic evicted a
victim's blocking row and the next replay was accepted. A replay guard whose rows can be
removed by somebody else's activity is not a guard.

## Decision drivers

1. ASVS 2.8.4: once within the validity period, not once per purpose
2. A guard's rows must not be removable by another user's traffic
3. Whatever replaces the key must survive on MySQL, PostgreSQL and SQLite alike
4. ADR-0015's other decisions are sound and must not be disturbed

## Decision

**The replay guard keys on `(user_id, time_step)`.** `purpose` is dropped from the key and
from the table.

`purpose` remains meaningful for audit — knowing *what* a code was redeemed for is worth
recording — but it belongs in the audit trail, not in the uniqueness constraint. A column
that widens the key is a column that authorises a replay.

**The pruning index becomes `(user_id, used_at)`** and the prune scopes by `user_id`, so
one identity's expiry can no longer evict another's row.

Delivered by `src/Auth/Database/Migration/20260805000001_totp_replay_guard_drop_purpose.php`,
which deduplicates `(user_id, time_step)` before narrowing the key — with `purpose` in the
key a single pair can hold one row per purpose, and all but one violate the new key.

## Alternatives considered

### Keep `purpose` in the key and rate-limit per purpose instead

Rejected. Rate limiting bounds how *often* a code may be presented; it does not make a
redeemed code unusable. ASVS 2.8.4 is a single-use requirement, and a control that slows
reuse is not a control that prevents it. It also leaves the guarantee dependent on a
limiter being configured, which ADR-0015 explicitly made optional.

### Return to `(identityId, code)`

Rejected. Six-digit codes recur over an account's lifetime, so this key refuses a
legitimate future code for no reason other than that its digits were seen before. The
failure is silent from the user's side — a correct code rejected — which is the worst
shape a false positive can take in an authentication path.

### Scope the key per purpose but share a single-use ledger across purposes

Rejected as the same thing with more moving parts. Two structures that must agree about
whether a code has been redeemed will eventually disagree, and the disagreement is a
replay.

## Consequences

### Positive

- A TOTP code is redeemable exactly once within its time step, whatever it is presented for.
- One user's pruning can no longer delete another user's blocking row.
- The table is narrower and the key shorter.

### Negative

- The schema change is not free: the migration deduplicates rows, and on SQLite it rebuilds
  the table because SQLite cannot drop a primary-key column at any version.
- An application that relied on redeeming one code for two purposes in the same 30-second
  window will now see the second attempt refused. That behaviour was the defect.

### Neutral

- `down()` restores the `purpose` column but deliberately does **not** restore it to the
  key. Rolling this back must not silently make a code redeemable three times again; an
  operator who genuinely wants the old behaviour has to ask for it explicitly.

## Security impact

Closes a verifier-side replay hole: three redemptions of one code reduced to one. The
attack it removes is the one the control existed to stop, so the impact is the difference
between having the control and appearing to.

Removing `purpose` from the table removes it from the replay guard only. Audit events
continue to record the purpose of every 2FA decision, per ADR-0015, so nothing that
compliance evidence depends on is lost.

## Performance impact

Neutral to positive. The key is one column shorter and the table one column narrower. The
pruning index gains a column but the `DELETE` that uses it gains a `user_id` predicate, so
it scans one identity's rows rather than every identity's.

## Migration / rollback plan

Forward: run the migrations. `20260327000001_create_2fa_tables` had to be repaired at the
same time — it wrote `CREATE INDEX IF NOT EXISTS`, which MySQL rejects at parse time, and
died on the statement before the replay-guard table was created. On MySQL the table had
therefore never existed and this ADR's migration had nothing to correct. Both now go
through `Pulsar\Database\Schema\IndexOperations`.

Every step is individually guarded rather than the whole `up()` being skipped when the
column is already gone. MySQL and SQLite commit each DDL statement as it runs while the
migration runner records a migration only once `up()` returns, so a run that dies partway
runs again from the top — and an early return would report success over a half-migrated
table.

Rollback: `down()` restores the column with a default and puts the original index back. It
does not restore the wide key, for the reason given above.

Verified against SQLite, MySQL 8.0 and PostgreSQL 16 by
`tests/Contract/TotpReplayGuardMigrationContractTest`, which `require`s the migration files
rather than reimplementing them.

## Links

- [ADR-0015](0015-identity-scoped-two-factor-authentication.md) — partially superseded
- [ADR-0001](0001-ci-gates-and-adr-discipline.md) — ADR discipline
- ASVS 4.0.3 § 2.8.4
- `docs/security/asvs-l2-matrix.md`, row V2.8
