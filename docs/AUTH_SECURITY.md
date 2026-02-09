# Authentication Security

Threat model, secure defaults, and deployment guidance for Pulsar's authentication subsystems: two-factor authentication (2FA), social single sign-on (SSO), session management, and step-up re-authentication.

---

## Threat Model

### Authentication Threats

| Threat                       | Attack vector                                                     | Mitigation                                                                                                                          |
| ---------------------------- | ----------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Credential stuffing          | Automated login attempts using leaked credential lists            | Rate limiting via `TwoFactorRateLimiterInterface` with `ip`, `user_agent_hash`, `device_id` context keys; audit trail for detection |
| Brute force (TOTP)           | Exhaustive 6-digit code enumeration (10^6 combinations)           | Rate limiter blocks after threshold; replay guard prevents reuse of already-accepted time steps                                     |
| Session hijacking            | Stolen session cookie via XSS or network sniffing                 | `HttpOnly`, `Secure`, `SameSite=Strict` cookie flags; session regeneration on privilege escalation                                  |
| Session fixation             | Attacker pre-sets session ID before victim authenticates          | `regenerate()` called after 2FA verification and step-up authentication; `use_strict_mode` rejects uninitialized session IDs        |
| TOTP replay                  | Re-submitting a previously valid TOTP code within the time window | Replay guard keyed on `(identityId, purpose, timeStep)` rejects duplicate time steps                                                |
| TOTP clock drift abuse       | Submitting codes from far-future or far-past time steps           | Verification window limits accepted time steps (default: 1 step = +/- 30 seconds)                                                   |
| Recovery code guessing       | Brute-forcing 64-bit recovery codes                               | 64-bit entropy (2^64 combinations); BLAKE2b HMAC hashing; rate limiter integration                                                  |
| Recovery code race condition | Two concurrent requests consuming the same recovery code          | Atomic `consume()` in `RecoveryCodeStoreInterface`; exactly one request succeeds, the other receives `AlreadyUsed`                  |

### SSO / OAuth Threats

| Threat                       | Attack vector                                                      | Mitigation                                                                                                                                                                              |
| ---------------------------- | ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| State parameter CSRF         | Attacker initiates OAuth flow and tricks victim into completing it | 256-bit random state tokens; one-time consume on verification; TTL expiry (default: 300 seconds)                                                                                        |
| Nonce replay                 | Reusing an ID token nonce across sessions                          | Session-backed nonce storage; one-time consume via `hash_equals()`; verified only from `IdTokenClaims` (post-signature-verification), never from unverified claims                      |
| PKCE downgrade               | Forcing plain code challenge method instead of S256                | S256-only enforcement; `plain` method is not supported                                                                                                                                  |
| Authorization code injection | Substituting a stolen authorization code in the callback           | PKCE verifier bound to state token; code exchange requires matching verifier                                                                                                            |
| Token leakage                | Secrets exposed in logs, error messages, or debug output           | `#[\SensitiveParameter]` on all `$code`, `$secret`, `$accessToken`, `$refreshToken`, `$idToken` parameters; `__debugInfo()` redaction on `OAuthTokenSet`, `ProviderConfig`, `MasterKey` |
| Open redirector              | Manipulating redirect URI to exfiltrate authorization codes        | Redirect URIs resolved from `ProviderConfig` only; `CallbackController` never reads redirect URI from request parameters                                                                |
| ID token signature bypass    | Forged or unsigned ID tokens accepted as valid                     | `alg: "none"` rejected unconditionally; JWKS signature verification required; unsupported algorithms rejected                                                                           |
| ID token claim manipulation  | Tampered claims (wrong issuer, expired, wrong audience)            | Full OIDC claim validation: `iss`, `aud`, `exp`, `iat`, `nonce`, `azp` (required when multi-audience); clock skew tolerance (default: 120 seconds)                                      |

### 2FA-Specific Threats

| Threat                          | Attack vector                                                | Mitigation                                                                                                                                                                                                    |
| ------------------------------- | ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| TOTP secret exposure            | Secrets readable in storage, logs, or debug output           | Encrypt-at-rest via `TotpSecretStoreInterface` (AEAD with `identityId` as AAD); `#[\SensitiveParameter]` annotations; `__debugInfo()` redaction                                                               |
| Recovery code plaintext storage | Codes stored unhashed, readable by DB administrators         | BLAKE2b HMAC hashing with derived subkey (id=3, context=`rcvrycod`); only hashes stored                                                                                                                       |
| Cross-identity replay           | TOTP secret encrypted for identity A decrypted as identity B | AEAD associated data (AAD) includes `identityId`; decryption fails on identity mismatch                                                                                                                       |
| Step-up inheritance             | User A's step-up session mistakenly applied to user B        | Session key scoped per identity: `_pulsar_step_up[{identityId}]`; different identities cannot inherit step-up status                                                                                          |
| Secret leakage to observability | Sensitive values exposed in Studio or event collectors       | `AuthEventCollectorInterface` uses allowlist-based metadata: only `action`, `outcome`, `identity_id`, `purpose`, `reason`, `code_index`, `provider`, `remaining_codes` are emitted; unknown keys are stripped |

### Session Threats

| Threat                     | Attack vector                                                   | Mitigation                                                                                                                                              |
| -------------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Fixation                   | Attacker pre-sets session ID                                    | Session regeneration on login, 2FA verification, and step-up authentication                                                                             |
| Idle timeout bypass        | Stale sessions remain valid indefinitely                        | Configurable session lifetime; step-up timeout (default: 15 minutes)                                                                                    |
| Cookie theft               | Session cookie stolen via XSS or man-in-the-middle              | `HttpOnly` (no JavaScript access), `Secure` (HTTPS only), `SameSite=Strict` (no cross-origin sending)                                                   |
| Cross-site request forgery | Attacker triggers state-changing requests from victim's browser | CSRF middleware with token validation on unsafe methods (`POST`, `PUT`, `PATCH`, `DELETE`); origin/referer validation with configurable trusted origins |

---

## Safe Defaults by Environment

### Production

| Setting                          | Default                            | Rationale                             |
| -------------------------------- | ---------------------------------- | ------------------------------------- |
| `cookie_secure`                  | `true`                             | HTTPS-only cookie transmission        |
| `cookie_samesite`                | `Strict`                           | No cross-origin cookie sending        |
| `cookie_httponly`                | `true`                             | No JavaScript cookie access           |
| `regenerate_on_privilege_change` | `true`                             | Session fixation prevention           |
| `csrf.enabled`                   | `true`                             | CSRF protection on all unsafe methods |
| `two_factor.allowInMemory`       | `false`                            | In-memory stores emit `WARNING` log   |
| `require_pkce`                   | `true`                             | PKCE S256 required for all SSO flows  |
| `require_nonce`                  | `true`                             | Nonce verification required for OIDC  |
| `allowUnverifiedIdToken`         | `false`                            | ID tokens must be signature-verified  |
| Replay guard                     | Persistent (SQLite minimum)        | Survives restarts                     |
| Recovery code store              | Persistent (DB-backed)             | Atomic consume, survives restarts     |
| TOTP secret store                | Persistent + encrypted (DB-backed) | Encrypt-at-rest with AEAD             |

**Production guardrail:** When in-memory stores (`InMemoryTotpSecretStore`, `InMemoryRecoveryCodeStore`, `InMemoryTotpReplayGuard`) are active and `two_factor.allowInMemory` is `false`, the framework emits a `WARNING` log at boot:

```
In-memory 2FA store [InMemoryTotpSecretStore] is active — data will not persist across restarts. Bind a persistent implementation.
```

This is intentionally loud. Set `two_factor.allowInMemory: true` only when you explicitly accept the risk.

### Development

Same defaults as production (security by default). Exceptions:

- `cookie_secure` can be relaxed for `localhost`
- In-memory stores are acceptable for local development
- `LocalMockProvider` for SSO testing (no network calls)

### Testing

- `LocalMockProvider` for deterministic SSO flows
- `InMemoryTotpReplayGuard` for replay guard
- `InMemoryRecoveryCodeStore` for recovery code store
- `InMemoryTotpSecretStore` for TOTP secret store
- No network calls, no external dependencies

---

## Enabling 2FA Safely

### Setup Flow

1. Call `beginSetup()` with the authenticated identity. Returns:
   - `secret` — raw binary secret (display only once)
   - `secret_base32` — Base32-encoded for manual entry
   - `provisioning_uri` — QR code URI (`otpauth://totp/...`)
   - `recovery_codes` — plaintext codes (display only once, never again)
   - `recovery_code_set` — `RecoveryCodeSet` with hashed codes for storage
2. User scans QR code in their authenticator app
3. User enters a TOTP code to confirm setup
4. Call `confirmSetup()` with the identity ID, secret, and code
5. On success, the secret is stored encrypted via `TotpSecretStoreInterface`
6. On failure, `Confirm2faSetupResult.reason` indicates the cause

### Verification Flow

1. Call `verifyCode()` with identity ID, code, and purpose
2. The manager loads the secret from `TotpSecretStoreInterface`
3. The verifier checks the code against the current and adjacent time steps
4. The replay guard rejects previously-accepted `(identityId, purpose, timeStep)` tuples
5. `Verify2faResult` contains:
   - `verified` — boolean success/failure
   - `reason` — `Valid`, `InvalidCode`, `Replayed`, `Expired`, `NotEnrolled`, `RateLimited`
   - `purpose` — `Login`, `Setup`, `StepUp`
   - `acceptedTimeStep` — the accepted time step (null on failure)
6. On success, the session is regenerated (privilege escalation defense)

### Recovery Code Storage

- Codes are hashed with BLAKE2b HMAC using a derived subkey (master key id=3, context=`rcvrycod`)
- Only hashes are stored; plaintext codes are shown exactly once during setup
- Input is canonicalized (uppercase, stripped dashes/spaces) before hashing
- Code format: `XXXX-XXXX-XXXX-XXXX` (64-bit entropy, 16 hex characters)
- Legacy format: `XXXX-XXXX` (32-bit entropy, detected by canonical length)

### Recovery Code Rotation

- Call `rotateRecoveryCodes()` with identity ID
- Generates a new `RecoveryCodeSet`, stores it, invalidates the old set
- Returns `RecoveryCodeRotationResult` with the new set and plaintext codes
- Audit event `2fa_recovery_codes_rotated` is emitted

### Rate Limiting

Implement `TwoFactorRateLimiterInterface` and bind it in the container:

```php
attempt(string $identityId, TwoFactorPurpose $purpose, array $context = []): bool
reset(string $identityId): void
```

The `$context` array supports these keys (populate as needed):

| Key               | Description                    |
| ----------------- | ------------------------------ |
| `ip`              | Client IP address              |
| `user_agent_hash` | Hashed user agent string       |
| `device_id`       | Persistent device fingerprint  |
| `route`           | Route name for step-up context |
| `session_id`      | Hashed session ID              |

When `attempt()` returns `false`, the manager returns `VerifyReason::RateLimited` without attempting verification.

---

## Enabling SSO Safely

### PKCE Requirement

All OAuth flows use PKCE with S256. The `plain` method is not supported. PKCE verifiers are bound to state tokens in the session, preventing tab A/B overwrite in concurrent login flows.

### State and Nonce Verification

- **State tokens**: 256-bit random, stored in session with TTL (default: 300 seconds), consumed on verification (one-time use)
- **Nonces**: 256-bit random, stored in session, verified via `hash_equals()` (constant-time comparison), only checked from `IdTokenClaims` (post-signature-verification)

### ID Token Verification

The `JwksIdTokenVerifier` enforces strict rules:

1. **`alg: "none"` is rejected unconditionally** — unsigned tokens are never accepted
2. **Unsupported algorithms are rejected** — only RS256 and ES256 are supported
3. **`kid` handling**: Required when JWKS has multiple keys; single-key JWKS without `kid` uses that key
4. **`aud` handling**: Must include `clientId`; when multi-valued, `azp` must be present and equal `clientId`
5. **`jwksUri` must use HTTPS** — validated at config time in `ProviderConfig::fromArray()`
6. **Clock skew**: Applied to `exp`, `iat`, and `nbf` (default: 120 seconds)
7. **JWKS caching**: In-memory cache with rotation on unknown `kid`

### Client Secret Management

- `ProviderConfig` annotates `$clientSecret` with `#[\SensitiveParameter]`
- `__debugInfo()` returns `[REDACTED]` for `clientSecret`
- Store client secrets in environment variables or a secrets manager, never in version control

### Redirect URI Configuration

Redirect URIs are resolved from `ProviderConfig->redirectUri` or derived from the router's named route (`sso.callback`). The `CallbackController` never reads a redirect URI from request parameters. This prevents open redirector attacks by design.

### Provider Configuration

```php
ProviderConfig::fromArray('google', [
    'type' => 'oidc',                    // 'oidc' or 'oauth2'
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token_url' => 'https://oauth2.googleapis.com/token',
    'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
    'issuer' => 'https://accounts.google.com',
    'scopes' => ['openid', 'profile', 'email'],
    'redirect_uri' => '/auth/sso/callback/google',
]);
```

When `type` is `oauth2` (no OIDC), an unexpected `id_token` in the response is rejected unless `allow_unverified_id_token: true` is explicitly set. This is documented as insecure and should not be used in regulated environments.

---

## Step-Up Authentication

Step-up re-authentication protects sensitive operations (password changes, payment approvals, admin actions) by requiring a recent 2FA verification.

### How It Works

1. Apply `StepUpMiddleware` to protected routes
2. The middleware checks the session key `_pulsar_step_up[{identityId}]` for a timestamp
3. If the timestamp is missing or older than `stepUpTimeoutMinutes` (default: 15), the request is denied with HTTP 403
4. After successful 2FA verification with `TwoFactorPurpose::StepUp`, call:
   ```php
   StepUpMiddleware::markStepUpAuthenticated($session, $identityId);
   ```
5. The session key is scoped per identity — user A's step-up does not apply to user B

### Response Format

- JSON requests (`Accept: application/json` or `X-Requested-With: XMLHttpRequest`): JSON error body
- Browser requests (`Accept: text/html`): HTML error page

---

## Audit Trail

All 2FA operations emit structured audit events via `AuditLogger`:

| Event                           | Category       | Outcome          | Metadata                           |
| ------------------------------- | -------------- | ---------------- | ---------------------------------- |
| `2fa_setup_initiated`           | SecurityEvent  | Success          | `identity_id`                      |
| `2fa_setup_confirmed`           | SecurityEvent  | Success          | `identity_id`                      |
| `2fa_setup_confirmation_failed` | SecurityEvent  | Failure          | `identity_id`, `reason`            |
| `2fa_code_verified`             | Authentication | Success          | `identity_id`, `purpose`           |
| `2fa_code_verification_failed`  | Authentication | Failure / Denied | `identity_id`, `purpose`, `reason` |
| `2fa_code_replayed`             | SecurityEvent  | Denied           | `identity_id`, `purpose`, `reason` |
| `2fa_recovery_code_used`        | Authentication | Success          | `identity_id`, `code_index`        |
| `2fa_recovery_code_replayed`    | SecurityEvent  | Denied           | `identity_id`, `reason`            |
| `2fa_recovery_code_failed`      | Authentication | Failure          | `identity_id`, `reason`            |
| `2fa_recovery_codes_rotated`    | SecurityEvent  | Success          | `identity_id`                      |

Metadata never contains TOTP codes, secrets, or recovery code values. The `reason` field uses `VerifyReason` or `ConsumeReason` enum values.

### Interpreting Audit Events

- **Clusters of `2fa_code_verification_failed`** with `reason: invalid_code` for a single identity may indicate brute force
- **`2fa_code_replayed`** events indicate replay attempts (may be benign if a user double-submits, or malicious)
- **`2fa_recovery_code_replayed`** with `reason: already_used` indicates a consumed code was resubmitted
- **`2fa_code_verification_failed`** with `reason: rate_limited` indicates the rate limiter intervened
- **`2fa_code_verification_failed`** with `reason: not_enrolled` indicates a verification attempt for an identity without 2FA configured

---

## Multi-Node Deployment

In-memory implementations are suitable for development and testing only. For multi-node production deployments:

### Replay Guard

Bind a persistent `TotpReplayGuardInterface` implementation backed by a shared data store (Redis, database). The composite key `(identityId, purpose, timeStep)` must be globally unique across all nodes. TTL = `windowSteps * period + driftPadding` (e.g., `1 * 30 + 30 = 60` seconds).

### Recovery Code Store

Bind a persistent `RecoveryCodeStoreInterface` implementation with atomic `consume()`. Database-level constraints (row-level locks or compare-and-swap) ensure exactly-once consumption even under concurrent requests from different nodes.

### TOTP Secret Store

Bind a persistent `TotpSecretStoreInterface` implementation that encrypts secrets at rest. AEAD with `identityId` as associated data prevents cross-identity ciphertext replay. The framework derives the encryption subkey from `MasterKey` (id=4, context=`totpscrt`).

### Session Store

Use a shared session backend (Redis, database) so that session-based state (step-up timestamps, SSO state tokens, PKCE verifiers, nonces) is accessible from any node.

---

## Configuration Reference

### Two-Factor Authentication (`config/security.php` → `two_factor`)

| Key                            | Type     | Default    | Description                             |
| ------------------------------ | -------- | ---------- | --------------------------------------- |
| `enabled`                      | `bool`   | `false`    | Enable 2FA subsystem                    |
| `issuer`                       | `string` | `'Pulsar'` | TOTP provisioning URI issuer            |
| `codeDigits`                   | `int`    | `6`        | TOTP code length (6-10)                 |
| `codePeriod`                   | `int`    | `30`       | TOTP time step in seconds               |
| `verificationWindow`           | `int`    | `1`        | Number of adjacent time steps to accept |
| `recoveryCodeCount`            | `int`    | `8`        | Number of recovery codes generated      |
| `recoveryCodeBytes`            | `int`    | `8`        | Bytes per recovery code (8 = 64-bit)    |
| `stepUpTimeoutMinutes`         | `int`    | `15`       | Step-up authentication timeout          |
| `recoveryCodeAlgorithmVersion` | `int`    | `2`        | Recovery code format version            |
| `allowInMemory`                | `bool`   | `false`    | Suppress in-memory store warnings       |

### Social SSO (`extensions/social-sso/config/social-sso.php`)

| Key               | Type      | Default | Description                         |
| ----------------- | --------- | ------- | ----------------------------------- |
| `enabled`         | `bool`    | `false` | Enable SSO extension                |
| `defaultProvider` | `?string` | `null`  | Default provider name               |
| `requirePkce`     | `bool`    | `true`  | Require PKCE S256 for all flows     |
| `requireNonce`    | `bool`    | `true`  | Require nonce verification for OIDC |
| `stateTtlSeconds` | `int`     | `300`   | State token TTL                     |

### Security Headers (`config/security.php` → `headers`)

Minimum defaults applied when not overridden:

| Header                   | Value                                      |
| ------------------------ | ------------------------------------------ |
| `X-Content-Type-Options` | `nosniff`                                  |
| `X-Frame-Options`        | `DENY`                                     |
| `Referrer-Policy`        | `strict-origin-when-cross-origin`          |
| `X-XSS-Protection`       | `0`                                        |
| `Permissions-Policy`     | `camera=(), microphone=(), geolocation=()` |

---

## Cryptographic Primitives

All cryptographic operations use libsodium per ADR-0006:

| Purpose                | Primitive                           | Key derivation                            |
| ---------------------- | ----------------------------------- | ----------------------------------------- |
| TOTP secret encryption | XSalsa20-Poly1305 (AEAD)            | MasterKey subkey id=4, context=`totpscrt` |
| Recovery code hashing  | BLAKE2b HMAC                        | MasterKey subkey id=3, context=`rcvrycod` |
| Audit chain integrity  | BLAKE2b HMAC                        | MasterKey via audit context               |
| Master key derivation  | `sodium_crypto_kdf_derive_from_key` | Single `PULSAR_MASTER_KEY` env var        |

**Exception:** The Social SSO extension uses OpenSSL for JWT signature verification (RS256, ES256) inside a scoped `JwtSignatureDriverInterface` implementation. This is explicitly documented as an extension-scoped dependency; the core framework remains libsodium-only.
