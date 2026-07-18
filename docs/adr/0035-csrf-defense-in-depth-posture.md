# ADR-0035: CSRF defense-in-depth posture and default token manager

## Status

Accepted

## Context

Pulsar ships three `CsrfTokenManagerInterface` implementations:

- `CsrfTokenManager` — session-backed synchronizer token. Default-wired.
- `StatelessCsrfManager` — keyed-MAC token, no server state (CDN/API).
- `EncryptedCsrfManager` — AEAD token binding session id, action, and a window.

The default manager traces to an early commit and was carried forward through
ADR-0014 "preserved exactly as implemented" — inherited, never decided. Three
gaps surfaced during hardening:

1. **Origin validation was inert by default.** `CsrfMiddleware` gated its
   Origin/Referer layer behind a non-empty `trusted_origins`, which defaults to
   `[]`. Every out-of-the-box install ran with Layer 1 disabled, and a
   deployment that set `origin_validation: 'required'` but left the allowlist
   empty got zero enforcement — failing open on the one setting an operator
   reaches for to harden.
2. **`StatelessCsrfManager` tokens were not bound to the browser.** The MAC
   covered only `timestamp || action`, so any visitor — including an attacker —
   could mint a token valid for every victim within the window (issue #425). It
   was not a CSRF defense standalone.
3. **`EncryptedCsrfManager` had construction traps.** It captured the session id
   as a string at construction; a boot singleton would freeze the empty
   pre-session id and validate every anonymous client's tokens against each
   other (`hash_equals('', '')` is true). Its `rotate()` was a no-op, its expiry
   window was untestable (direct `time()`), and its docblock claimed tokens
   "cannot be replayed" though it tracked no replay.

A recurring failure mode in this codebase is "primitive delivered, guarantee not
wired." The question was whether to fix the posture by swapping the default
token primitive (to `EncryptedCsrfManager`) or by completing the layering around
the existing default.

## Decision drivers

1. **Works on every install.** The default must not depend on optional key
   material or env configuration.
2. **Real rotation / server-side invalidation** on privilege boundaries.
3. **Defense in depth**, per the 2026 consensus (OWASP; Go 1.25
   `CrossOriginProtection`; Django/Rails forgery checks): no single mechanism is
   the last line.
4. **No false rejections** of legitimate non-browser clients.

## Decision

**Keep `CsrfTokenManager` (session synchronizer) as the default.** It is the
only implementation that needs zero key material, honors `rotate()` with real
anti-fixation (`Session::regenerate(true)`), and allows immediate server-side
invalidation. Defaulting to `EncryptedCsrfManager` would make CSRF protection
conditional on `PULSAR_MASTER_KEY`, regress rotation to a no-op, and buy nothing
reachable — its action binding and expiry are unreachable through the default
middleware, which only ever calls `validate()` with the interface's single
action.

The no-compromise posture is the **three layers**, all on by default:

- **Layer 1 — SameSite=Strict cookies.** Already the shipped default
  (`SessionConfig`, `config/security.php`). Primary control.
- **Layer 2 — cross-origin rejection.** Now on whenever `origin_validation` is
  not `off`, with the expected origin **derived from the request's own
  scheme+host** — no allowlist required. `trusted_origins` only adds origins; an
  empty list means "same-origin only", not "disabled". `Origin: null` is treated
  as cross-origin; `Sec-Fetch-Site: cross-site` is rejected even without Origin;
  a fully signal-less request (non-browser, no ambient cookies, so no CSRF
  vector) falls through to the token in `optional` mode and is rejected in
  `required`.
- **Layer 3 — session-bound synchronizer token**, masked per response against
  BREACH (see security-baseline.md).

**The two non-default managers are hardened into safe opt-ins**, not removed:

- `EncryptedCsrfManager` now takes a `SessionInterface` and reads the id fresh
  per operation (fixing the freeze trap and making a post-login regenerate
  rebind automatically), fails closed when no session is active, has a real
  `rotate()` (regenerate + reissue), an injectable clock (testable window), an
  honest docblock, and an optional `CsrfReplayGuardInterface` for single-use.
- `StatelessCsrfManager` (issue #425) gains a browser binding (signed
  double-submit cookie) so a minted token cannot be paired without the victim's
  `__Host-` cookie; tracked separately.

### Replay tracking

In the CSRF threat model the attacker never holds the token (the request is a
blind cross-site one), so replay is a narrow, side-channel threat. The default
session manager is already single-use per rotation. For the opt-in
`EncryptedCsrfManager`, single-use is offered — not forced — via
`CsrfReplayGuardInterface`: injected, the token's nonce is consumed on first
acceptance (giving the previously-dead `n` field a purpose); absent, the token
is replayable within its window by design. The guard's `consume()` must be
atomic (back it with the cache driver's `add()`/SETNX at the composition root);
a plain has-then-set is a compromise, not an implementation of the contract.
The guard is not shipped as a concrete Security-module class because it would
cross the module boundary into the cache layer — it is wired as a small adapter
at the composition root, alongside the opt-in manager itself.

## Alternatives considered

- **Default to `EncryptedCsrfManager`.** Rejected: env-key dependency, no-op
  rotation regression, no server-side invalidation, and its advantages
  unreachable through the default pipeline. Verified against the code.
- **Origin `required` by default.** Rejected: 403s legitimate token-bearing
  non-browser clients (which cannot be CSRF vectors) for no gain. Available for
  deployments that know all clients are evergreen browsers.
- **Boot-fail when neither `app.url` nor `trusted_origins` is set.** Rejected:
  breaks the zero-config promise and every current install. Same-origin
  derivation is fail-closed (a scheme mismatch behind a proxy yields a false
  reject, not a bypass), with `trusted_origins` as the documented escape hatch.

## Consequences

### Positive

- Every install gets cross-origin rejection with no configuration.
- The default manager keeps real rotation and server-side invalidation.
- The two opt-in managers become safe to adopt.

### Negative

- Deployments behind a TLS terminator that hides the scheme must add their
  public origin to `trusted_origins` (documented).
- Deployments that previously set `origin_validation: 'required'` with an empty
  allowlist were getting zero enforcement and will start enforcing same-origin —
  the intended fix of a silent misconfiguration, release-noted.

## Links

- ADR-0006: Libsodium-only crypto and master key derivation
- ADR-0014: Kernel service wiring decomposition
- security-baseline.md: CSRF sections (BREACH masking, Origin layer)
- Issue #425: StatelessCsrfManager browser binding
