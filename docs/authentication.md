# Authentication

Pulsar provides a guard-based authentication system with lazy identity resolution, session and token guards, password hashing, and two-factor authentication (TOTP + recovery codes). All auth services are configured via the `auth` section of `config/security.php` and registered automatically in the Kernel boot pipeline.

## Architecture

### Hybrid identity resolution

Authentication uses a two-tier approach to avoid paying the cost of full authentication on every request:

1. **Global middleware** (`AuthenticationMiddleware`) runs on every request but only performs cheap work - creating a `SecurityContext` wrapper and setting a default `AnonymousIdentity`.
2. **Route-level middleware** (`AuthorizationMiddleware`, `TwoFactorMiddleware`) triggers full identity resolution via `SecurityContext::identity()` only on protected routes.

```
Request
  │
  ▼
AuthenticationMiddleware (global)
  │  Sets _security_context (lazy)
  │  Sets _identity = AnonymousIdentity
  ▼
AuthorizationMiddleware (route-level, optional)
  │  Calls SecurityContext::identity() → triggers AuthManager::authenticate()
  │  Checks Gate permissions
  ▼
TwoFactorMiddleware (route-level, optional)
  │  Checks TwoFactorStatus
  ▼
Handler
```

Handlers on unprotected routes receive `AnonymousIdentity` by default. If they need the real identity (e.g., for personalization), they trigger lazy resolution explicitly:

```php
$context = $request->attribute('_security_context');
$identity = $context->identity(); // Resolves on first call, cached thereafter
```

## Configuration

### config/security.php

```php
return [
    // ... existing session, csrf, headers, rate_limit config ...

    'auth' => [
        'default_guard' => 'session',

        'guards' => [
            ['name' => 'session', 'driver' => 'session', 'enabled' => true],
            ['name' => 'token', 'driver' => 'token', 'enabled' => true],
        ],

        'two_factor' => [
            'enabled' => false,
            'issuer' => 'Pulsar',
            'code_digits' => 6,
            'code_period' => 30,
            'verification_window' => 1,
            'recovery_code_count' => 8,
        ],

        'authorization' => [
            'roles' => [
                'admin' => ['permissions' => ['*']],
                'editor' => ['permissions' => ['content.view', 'content.create', 'content.edit']],
                'viewer' => ['permissions' => ['content.view']],
            ],
            'super_roles' => ['admin'],
        ],
    ],
];
```

### Config DTOs

| Class                 | Responsibility                        |
| --------------------- | ------------------------------------- |
| `AuthConfig`          | Top-level; composes guard, 2FA, authz |
| `AuthGuardConfig`     | Guard name, driver, enabled flag      |
| `TwoFactorConfig`     | TOTP settings and recovery code count |
| `AuthorizationConfig` | Role definitions and super-role list  |

All DTOs are `readonly` classes with `fromArray()` factory methods.

## Identity

### IdentityInterface

The core contract for any authenticated (or anonymous) identity:

```php
interface IdentityInterface
{
    public function id(): string;
    public function displayName(): string;
    /** @return list<string> */
    public function roles(): array;
    public function hasRole(string $role): bool;
    public function twoFactorStatus(): TwoFactorStatus;
    public function isAuthenticated(): bool;
    /** @return array<string, mixed> */
    public function attributes(): array;
    public function attribute(string $key, mixed $default = null): mixed;
}
```

### Identity

Readonly value object implementing `IdentityInterface`. Supports session serialization and immutable 2FA status transitions:

```php
$identity = new Identity(
    id: 'user-42',
    displayName: 'Jane Doe',
    roles: ['admin'],
    twoFactorStatus: TwoFactorStatus::Disabled,
    attributes: ['email' => 'jane@example.com'],
);

// Serialize for session storage
$data = $identity->toArray();

// Reconstitute from session
$restored = Identity::fromArray($data);

// Immutable 2FA status update
$verified = $identity->withTwoFactorStatus(TwoFactorStatus::Verified);
```

### AnonymousIdentity

Null object pattern for unauthenticated requests:

- `id()` returns `''`
- `displayName()` returns `'Anonymous'`
- `isAuthenticated()` returns `false`
- `roles()` returns `[]`

### TwoFactorStatus

```php
enum TwoFactorStatus: string
{
    case Disabled = 'disabled';  // 2FA not enabled
    case Pending  = 'pending';   // Authenticated but awaiting 2FA code
    case Verified = 'verified';  // Full authentication including 2FA
}
```

## Guards

Guards extract identity from the request. Each guard returns `null` if it cannot authenticate, allowing the next guard to try.

### GuardInterface

```php
interface GuardInterface
{
    public function authenticate(Request $request): ?IdentityInterface;
    public function name(): string;
}
```

### SessionGuard

Session-based authentication using `SessionInterface` from the security subsystem.

```php
$guard = new SessionGuard($session);

// Login - stores identity and regenerates session ID (prevents fixation)
$guard->login($identity);

// Authenticate - reads identity from session
$resolved = $guard->authenticate($request); // ?IdentityInterface

// Update identity without session regeneration (e.g., after 2FA verification)
$guard->updateIdentity($verifiedIdentity);

// Logout - removes identity and regenerates session ID
$guard->logout();
```

Session key: `_pulsar_identity`

### TokenGuard

Bearer token authentication. Extracts the token from the `Authorization` header and delegates resolution to a `TokenResolverInterface`:

```php
// Request: Authorization: Bearer <token>
$guard = new TokenGuard($resolver);
$identity = $guard->authenticate($request);
```

### TokenResolverInterface

Applications implement this interface to integrate JWT, API keys, or custom token schemes:

```php
interface TokenResolverInterface
{
    public function resolve(string $token): ?IdentityInterface;
}
```

Register the implementation in the container. The Kernel creates `TokenGuard` only if `TokenResolverInterface` is bound:

```php
$container->instance(TokenResolverInterface::class, new JwtTokenResolver($secretKey));
```

### Custom guards

Implement `GuardInterface` and register with `AuthManager`:

```php
final readonly class ApiKeyGuard implements GuardInterface
{
    public function authenticate(Request $request): ?IdentityInterface
    {
        $key = $request->header('X-Api-Key');
        if ($key === null) {
            return null;
        }
        return $this->repository->findByApiKey($key);
    }

    public function name(): string
    {
        return 'api-key';
    }
}
```

## AuthManager

Orchestrates multiple guards in registration order, falling back to `AnonymousIdentity` if none can authenticate:

```php
$manager = new AuthManager(defaultGuardName: 'session');
$manager->addGuard($sessionGuard);
$manager->addGuard($tokenGuard);

// Tries session → token → returns AnonymousIdentity
$identity = $manager->authenticate($request);

// Access a specific guard
$sessionGuard = $manager->guard('session');
```

## SecurityContext

Lazy wrapper around `AuthManager`. Identity is resolved only on first access and cached:

```php
$context = new SecurityContext($authManager, $request);

// No authentication has happened yet

$identity = $context->identity();    // Triggers AuthManager::authenticate() once
$identity = $context->identity();    // Returns cached result
$context->isAuthenticated();         // Delegates to identity
```

## Password hashing

Argon2id password hashing via PHP's built-in functions. Defaults follow [OWASP 2024 recommendations](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html) for Argon2id:

```php
$hasher = new PasswordHasher();

$hash = $hasher->hash('secret-password');
$valid = $hasher->verify('secret-password', $hash);  // true
$rehash = $hasher->needsRehash($hash);                // false (fresh hash)
```

### Recommended production parameters

The default parameters match OWASP 2024 recommendations and should be used unless you have specific benchmarking data for your hardware:

| Parameter    | Default | OWASP minimum | Description           |
| ------------ | ------- | ------------- | --------------------- |
| `memoryCost` | 19456   | 19456 (19 MB) | Memory usage in KiB   |
| `timeCost`   | 2       | 2             | Number of iterations  |
| `threads`    | 1       | 1             | Degree of parallelism |

The hasher enforces minimum floors to prevent insecure configurations:

- `memoryCost` must be at least 15360 KiB (15 MiB)
- `timeCost` must be at least 2

Attempting to construct a `PasswordHasher` with parameters below these floors throws `InvalidArgumentException`.

### Custom Argon2id parameters

If your hardware supports higher costs, increase memory first (most effective defense against GPU attacks), then iterations:

```php
$hasher = new PasswordHasher(
    memoryCost: 65536, // 64 MiB
    timeCost: 3,
    threads: 1,
);
```

### Test environments

Tests should use cheap parameters with the `allowWeakParameters` flag to avoid slow hashing:

```php
$hasher = new PasswordHasher(
    memoryCost: 256,
    timeCost: 1,
    threads: 1,
    allowWeakParameters: true,
);
```

The `allowWeakParameters` flag bypasses floor validation. Never use this in production code.

## Two-factor authentication

### Overview

Pulsar includes TOTP (RFC 6238) generation and verification with recovery codes as a fallback. The 2FA system is optional and controlled via config.

### Setup flow

```php
$manager = $container->get(TwoFactorManagerInterface::class);

// 1. Begin setup - generates secret, provisioning URI, and recovery codes
$setup = $manager->beginSetup($identity);
// Returns:
// [
//     'secret' => '<binary>',
//     'secret_base32' => 'JBSWY3DPEHPK3PXP...',
//     'provisioning_uri' => 'otpauth://totp/Pulsar:user@example.com?...',
//     'recovery_codes' => ['A3F2-9B4C', '7D1E-F056', ...],
// ]

// 2. Display QR code from provisioning_uri, show recovery codes to user

// 3. Confirm setup - user enters code from authenticator app
$confirmed = $manager->confirmSetup($setup['secret'], $userCode);
```

### Verification flow

```php
// During login, after password verification:
$valid = $manager->verifyCode($secret, $userCode);

// Recovery code fallback:
$index = $manager->verifyRecoveryCode($userCode, $storedCodes);
// Returns matched index (0-based) or -1 if invalid
```

### Identity status transitions

After password authentication, if 2FA is enabled, the identity starts with `Pending` status:

```php
$identity = new Identity(
    id: 'user-1',
    displayName: 'User',
    roles: ['user'],
    twoFactorStatus: TwoFactorStatus::Pending,
);

// After TOTP verification, transition to Verified:
$verified = $identity->withTwoFactorStatus(TwoFactorStatus::Verified);

// Update the session guard with the verified identity:
$sessionGuard->updateIdentity($verified);
```

### TOTP internals

`TotpGenerator` implements RFC 6238 (TOTP) built on RFC 4226 (HOTP):

- Default: 6-digit codes, 30-second period, SHA-1 algorithm (uses PHP's `hash_hmac('sha1', ...)` per RFC 6238; this is an exception to the libsodium-only policy because TOTP mandates SHA-1 HMAC)
- `TotpVerifier` checks codes within a configurable time window (default ±1 period) to account for clock drift
- All comparisons use constant-time operations

### Recovery codes

- Generated in `XXXX-XXXX-XXXX-XXXX` format (8 random bytes = 16 hex chars, uppercase)
- Default: 8 codes per setup
- Verification is case-insensitive with constant-time comparison
- Each code is single-use - applications should mark used codes

## Middleware

### AuthenticationMiddleware (global)

Runs on every request. Attaches `SecurityContext` and default `AnonymousIdentity` to the request attributes. Does **not** trigger full authentication:

```php
// Registered automatically as global middleware by Kernel
// Sets: _security_context (SecurityContext), _identity (AnonymousIdentity)
```

### AuthorizationMiddleware (route-level)

Triggers lazy identity resolution and checks permissions via the Gate. Returns `401 Unauthorized` for anonymous users and `403 Forbidden` for insufficient permissions:

```php
// Apply via route middleware alias 'auth':
$route = new Route(
    methods: [Method::GET],
    path: '/admin/users',
    handler: $handler,
    attributes: ['permissions' => ['users.view']],
    middleware: ['auth'],
);
```

See [Authorization](authorization.md) for details on the Gate and permission model.

### TwoFactorMiddleware (route-level)

Blocks requests where `TwoFactorStatus` is `Pending`. Allows `Disabled` and `Verified`:

```php
// Apply via route middleware alias '2fa':
$route = new Route(
    methods: [Method::GET],
    path: '/dashboard',
    handler: $handler,
    middleware: ['2fa'],
);
```

## Exceptions

### AuthenticationException

Factory methods for common authentication failures:

| Method                 | Message                                  |
| ---------------------- | ---------------------------------------- |
| `invalidCredentials()` | `'Invalid credentials.'`                 |
| `unknownGuard($name)`  | `'Unknown guard: {name}.'`               |
| `noGuardsConfigured()` | `'No authentication guards configured.'` |
| `sessionExpired()`     | `'Session has expired.'`                 |
| `tokenMissing()`       | `'Authentication token is missing.'`     |
| `tokenInvalid()`       | `'Authentication token is invalid.'`     |

### AuthorizationException

Factory methods for authorization failures:

| Method                    | Message                                 |
| ------------------------- | --------------------------------------- |
| `permissionDenied($perm)` | `'Permission denied: {permission}.'`    |
| `roleNotFound($role)`     | `'Role not found: {role}.'`             |
| `policyDenied($policy)`   | `'Access denied by policy: {policy}.'`  |
| `unauthenticated()`       | `'Authentication required.'`            |
| `twoFactorRequired()`     | `'Two-factor authentication required.'` |

## Kernel boot integration

Auth services are registered during `Kernel::boot()` after security services:

```
Boot → Config → Logger → Tracer → Metrics → ErrorTracker → ExceptionHandler
  → SecurityServices → AuthServices → DiagnosticsRoute → Extensions
```

The `createAuthServices()` method registers services conditionally based on config and container state:

| Service                    | Condition                                   |
| -------------------------- | ------------------------------------------- |
| `PasswordHasher`           | Always                                      |
| `SessionGuard`             | `SessionInterface` bound in container       |
| `TokenGuard`               | `TokenResolverInterface` bound in container |
| `AuthManager`              | Always                                      |
| `InMemoryRoleRegistry`     | Always (populated from config roles)        |
| `Gate`                     | Always                                      |
| `TwoFactorManager`         | `two_factor.enabled` is `true`              |
| `AuthenticationMiddleware` | Always (registered as global middleware)    |
| `AuthorizationMiddleware`  | Always (alias: `'auth'`)                    |
| `TwoFactorMiddleware`      | Always (alias: `'2fa'`)                     |

## Related docs

- [Authorization](authorization.md): Gate, permissions, and policy model
- [Security baseline](security-baseline.md): secure defaults and hardening checklist
- [Compliance](compliance.md): regulatory requirements and audit evidence

---

## Appendix: security threat model

Threat model, secure defaults, and deployment guidance for Pulsar's authentication subsystems: two-factor authentication (2FA), social single sign-on (SSO), session management, and step-up re-authentication.

### Authentication threats

| Threat                       | Attack vector                                                     | Mitigation                                                                                                                          |
| ---------------------------- | ----------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Credential stuffing          | Automated login attempts using leaked credential lists            | Rate limiting via `TwoFactorRateLimiterInterface` with `ip`, `user_agent_hash`, `device_id` context keys; audit trail for detection |
| Brute force (TOTP)           | Exhaustive 6-digit code enumeration (10^6 combinations)           | Rate limiter blocks after threshold; replay guard prevents reuse of already-accepted time steps                                     |
| Session hijacking            | Stolen session cookie via XSS or network sniffing                 | `HttpOnly`, `Secure`, `SameSite=Strict` cookie flags; session regeneration on privilege escalation                                  |
| Session fixation             | Attacker pre-sets session ID before victim authenticates          | `regenerate()` called after 2FA verification and step-up authentication; `use_strict_mode` rejects uninitialized session IDs        |
| TOTP replay                  | Re-submitting a previously valid TOTP code within the time window | Replay guard keyed on `(identityId, purpose, timeStep)` rejects duplicate time steps                                                |
| TOTP clock drift abuse       | Submitting codes from far-future or far-past time steps           | Verification window limits accepted time steps (default: 1 step = +/- 30 seconds)                                                   |
| Recovery code guessing       | Brute-forcing 64-bit recovery codes                               | 64-bit entropy (2^64 combinations); keyed BLAKE2b hashing; rate limiter integration                                                 |
| Recovery code race condition | Two concurrent requests consuming the same recovery code          | Atomic `consume()` in `RecoveryCodeStoreInterface`; exactly one request succeeds, the other receives `AlreadyUsed`                  |

### SSO / OAuth threats

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

### 2FA-specific threats

| Threat                          | Attack vector                                                | Mitigation                                                                                                                                                                                                    |
| ------------------------------- | ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| TOTP secret exposure            | Secrets readable in storage, logs, or debug output           | Encrypt-at-rest via `TotpSecretStoreInterface` (authenticated encryption with `identityId` binding); `#[\SensitiveParameter]` annotations; `__debugInfo()` redaction                                          |
| Recovery code plaintext storage | Codes stored unhashed, readable by DB administrators         | keyed BLAKE2b hashing with derived subkey (id=3, context=`rcvrycod`); only hashes stored                                                                                                                      |
| Cross-identity replay           | TOTP secret encrypted for identity A decrypted as identity B | Ciphertext includes `identityId` in the encrypted payload; decryption verifies identity match                                                                                                                 |
| Step-up inheritance             | User A's step-up session mistakenly applied to user B        | Session key scoped per identity: `_pulsar_step_up[{identityId}]`; different identities cannot inherit step-up status                                                                                          |
| Secret leakage to observability | Sensitive values exposed in Studio or event collectors       | `AuthEventCollectorInterface` uses allowlist-based metadata: only `action`, `outcome`, `identity_id`, `purpose`, `reason`, `code_index`, `provider`, `remaining_codes` are emitted; unknown keys are stripped |

### Session threats

| Threat                     | Attack vector                                                   | Mitigation                                                                                                                                              |
| -------------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Fixation                   | Attacker pre-sets session ID                                    | Session regeneration on login, 2FA verification, and step-up authentication                                                                             |
| Idle timeout bypass        | Stale sessions remain valid indefinitely                        | Configurable session lifetime; step-up timeout (default: 15 minutes)                                                                                    |
| Cookie theft               | Session cookie stolen via XSS or man-in-the-middle              | `HttpOnly` (no JavaScript access), `Secure` (HTTPS only), `SameSite=Strict` (no cross-origin sending)                                                   |
| Cross-site request forgery | Attacker triggers state-changing requests from victim's browser | CSRF middleware with token validation on unsafe methods (`POST`, `PUT`, `PATCH`, `DELETE`); origin/referer validation with configurable trusted origins |

### Safe defaults by environment

#### Production

| Setting                          | Default                            | Rationale                                     |
| -------------------------------- | ---------------------------------- | --------------------------------------------- |
| `cookie_secure`                  | `true`                             | HTTPS-only cookie transmission                |
| `cookie_samesite`                | `Strict`                           | No cross-origin cookie sending                |
| `cookie_httponly`                | `true`                             | No JavaScript cookie access                   |
| `regenerate_on_privilege_change` | `true`                             | Session fixation prevention                   |
| `csrf.enabled`                   | `true`                             | CSRF protection on all unsafe methods         |
| `two_factor.allowInMemory`       | `false`                            | In-memory stores emit `WARNING` log           |
| `require_pkce`                   | `true`                             | PKCE S256 required for all SSO flows          |
| `require_nonce`                  | `true`                             | Nonce verification required for OIDC          |
| `allowUnverifiedIdToken`         | `false`                            | ID tokens must be signature-verified          |
| Replay guard                     | Persistent (SQLite minimum)        | Survives restarts                             |
| Recovery code store              | Persistent (DB-backed)             | Atomic consume, survives restarts             |
| TOTP secret store                | Persistent + encrypted (DB-backed) | Encrypt-at-rest with authenticated encryption |

**Production guardrail:** When in-memory stores (`InMemoryTotpSecretStore`, `InMemoryRecoveryCodeStore`, `InMemoryTotpReplayGuard`) are active and `two_factor.allowInMemory` is `false`, the framework emits a `WARNING` log at boot:

```
In-memory 2FA store [InMemoryTotpSecretStore] is active - data will not persist across restarts. Bind a persistent implementation.
```

Set `two_factor.allowInMemory: true` only when you explicitly accept the risk.

#### Development

Same defaults as production (security by default). Exceptions:

- `cookie_secure` can be relaxed for `localhost`
- In-memory stores are acceptable for local development
- `LocalMockProvider` for SSO testing (no network calls)

#### Testing

- `LocalMockProvider` for deterministic SSO flows
- `InMemoryTotpReplayGuard` for replay guard
- `InMemoryRecoveryCodeStore` for recovery code store
- `InMemoryTotpSecretStore` for TOTP secret store
- No network calls, no external dependencies

### Enabling 2FA safely

#### Setup flow (detailed)

1. Call `beginSetup()` with the authenticated identity. Returns:
   - `secret`: raw binary secret (display only once)
   - `secret_base32`: Base32-encoded for manual entry
   - `provisioning_uri`: QR code URI (`otpauth://totp/...`)
   - `recovery_codes`: plaintext codes (display only once, never again)
   - `recovery_code_set`: `RecoveryCodeSet` with hashed codes for storage
2. User scans QR code in their authenticator app
3. User enters a TOTP code to confirm setup
4. Call `confirmSetup()` with the identity ID, secret, and code
5. On success, the secret is stored encrypted via `TotpSecretStoreInterface`
6. On failure, `Confirm2faSetupResult.reason` indicates the cause

#### Verification flow (detailed)

1. Call `verifyCode()` with identity ID, code, and purpose
2. The manager loads the secret from `TotpSecretStoreInterface`
3. The verifier checks the code against the current and adjacent time steps
4. The replay guard rejects previously-accepted `(identityId, purpose, timeStep)` tuples
5. `Verify2faResult` contains:
   - `verified`: boolean success/failure
   - `reason`: `Valid`, `InvalidCode`, `Replayed`, `Expired`, `NotEnrolled`, `RateLimited`
   - `purpose`: `Login`, `Setup`, `StepUp`
   - `acceptedTimeStep`: the accepted time step (null on failure)
6. On success, the session is regenerated (privilege escalation defense)

#### Recovery code storage

- Codes are hashed with keyed BLAKE2b using a derived subkey (master key id=3, context=`rcvrycod`)
- Only hashes are stored; plaintext codes are shown exactly once during setup
- Input is canonicalized (uppercase, stripped dashes/spaces) before hashing
- Code format: `XXXX-XXXX-XXXX-XXXX` (64-bit entropy, 16 hex characters)
- Legacy format: `XXXX-XXXX` (32-bit entropy, detected by canonical length)

#### Recovery code rotation

- Call `rotateRecoveryCodes()` with identity ID
- Generates a new `RecoveryCodeSet`, stores it, invalidates the old set
- Returns `RecoveryCodeRotationResult` with the new set and plaintext codes
- Audit event `2fa_recovery_codes_rotated` is emitted

#### Rate limiting

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

### Enabling SSO safely

#### PKCE requirement

All OAuth flows use PKCE with S256. The `plain` method is not supported. PKCE verifiers are bound to state tokens in the session, preventing tab A/B overwrite in concurrent login flows.

#### State and nonce verification

- **State tokens**: 256-bit random, stored in session with TTL (default: 300 seconds), consumed on verification (one-time use)
- **Nonces**: 256-bit random, stored in session, verified via `hash_equals()` (constant-time comparison), only checked from `IdTokenClaims` (post-signature-verification)

#### ID token verification

The `JwksIdTokenVerifier` enforces strict rules:

1. `alg: "none"` is rejected unconditionally: unsigned tokens are never accepted
2. Unsupported algorithms are rejected: only RS256 and ES256 are supported
3. `kid` handling: required when JWKS has multiple keys; single-key JWKS without `kid` uses that key
4. `aud` handling: must include `clientId`; when multi-valued, `azp` must be present and equal `clientId`
5. `jwksUri` must use HTTPS: validated at config time in `ProviderConfig::fromArray()`
6. Clock skew: applied to `exp`, `iat`, and `nbf` (default: 120 seconds)
7. JWKS caching: in-memory cache with rotation on unknown `kid`

#### Client secret management

- `ProviderConfig` annotates `$clientSecret` with `#[\SensitiveParameter]`
- `__debugInfo()` returns `[REDACTED]` for `clientSecret`
- Store client secrets in environment variables or a secrets manager, never in version control

#### Redirect URI configuration

Redirect URIs are resolved from `ProviderConfig->redirectUri` or derived from the router's named route (`sso.callback`). The `CallbackController` never reads a redirect URI from request parameters, preventing open redirector attacks by design.

#### Provider configuration

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

When `type` is `oauth2` (no OIDC), an unexpected `id_token` in the response is rejected unless `allow_unverified_id_token: true` is explicitly set. This is insecure and should not be used in regulated environments.

### Step-up authentication

Step-up re-authentication protects sensitive operations (password changes, payment approvals, admin actions) by requiring a recent 2FA verification.

1. Apply `StepUpMiddleware` to protected routes
2. The middleware checks the session key `_pulsar_step_up[{identityId}]` for a timestamp
3. If the timestamp is missing or older than `stepUpTimeoutMinutes` (default: 15), the request is denied with HTTP 403
4. After successful 2FA verification with `TwoFactorPurpose::StepUp`, call:
   ```php
   StepUpMiddleware::markStepUpAuthenticated($session, $identityId);
   ```
5. The session key is scoped per identity: user A's step-up does not apply to user B

Response format:

- JSON requests (`Accept: application/json` or `X-Requested-With: XMLHttpRequest`): JSON error body
- Browser requests (`Accept: text/html`): HTML error page

### Audit trail

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

#### Interpreting audit events

- **Clusters of `2fa_code_verification_failed`** with `reason: invalid_code` for a single identity may indicate brute force
- **`2fa_code_replayed`** events indicate replay attempts (may be benign if a user double-submits, or malicious)
- **`2fa_recovery_code_replayed`** with `reason: already_used` indicates a consumed code was resubmitted
- **`2fa_code_verification_failed`** with `reason: rate_limited` indicates the rate limiter intervened
- **`2fa_code_verification_failed`** with `reason: not_enrolled` indicates a verification attempt for an identity without 2FA configured

### Multi-node deployment

In-memory implementations are suitable for development and testing only. For multi-node production deployments:

#### Replay guard

Bind a persistent `TotpReplayGuardInterface` implementation backed by a shared data store (Redis, database). The composite key `(identityId, purpose, timeStep)` must be globally unique across all nodes. TTL = `windowSteps * period + driftPadding` (e.g., `1 * 30 + 30 = 60` seconds).

#### Recovery code store

Bind a persistent `RecoveryCodeStoreInterface` implementation with atomic `consume()`. Database-level constraints (row-level locks or compare-and-swap) ensure exactly-once consumption even under concurrent requests from different nodes.

#### TOTP secret store

Bind a persistent `TotpSecretStoreInterface` implementation that encrypts secrets at rest. The `Encryptor` uses `sodium_crypto_secretbox` (authenticated encryption) with the default derived key (id=1, context=`encrypt_`). If the TOTP store binds `identityId` as a storage key, cross-identity confusion is prevented at the application layer.

#### Session store

Use a shared session backend (Redis, database) so that session-based state (step-up timestamps, SSO state tokens, PKCE verifiers, nonces) is accessible from any node.

### Configuration reference

#### Two-factor authentication (`config/security.php` -> `two_factor`)

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

#### Social SSO (`extensions/social-sso/config/social-sso.php`)

| Key               | Type      | Default | Description                         |
| ----------------- | --------- | ------- | ----------------------------------- |
| `enabled`         | `bool`    | `false` | Enable SSO extension                |
| `defaultProvider` | `?string` | `null`  | Default provider name               |
| `requirePkce`     | `bool`    | `true`  | Require PKCE S256 for all flows     |
| `requireNonce`    | `bool`    | `true`  | Require nonce verification for OIDC |
| `stateTtlSeconds` | `int`     | `300`   | State token TTL                     |

#### Security headers (`config/security.php` -> `headers`)

Minimum defaults applied when not overridden:

| Header                   | Value                                      |
| ------------------------ | ------------------------------------------ |
| `X-Content-Type-Options` | `nosniff`                                  |
| `X-Frame-Options`        | `DENY`                                     |
| `Referrer-Policy`        | `strict-origin-when-cross-origin`          |
| `X-XSS-Protection`       | `0`                                        |
| `Permissions-Policy`     | `camera=(), microphone=(), geolocation=()` |

### Cryptographic primitives

Cryptographic operations use libsodium per ADR-0006 (with noted exceptions):

| Purpose                | Primitive                                    | Key derivation                                                |
| ---------------------- | -------------------------------------------- | ------------------------------------------------------------- |
| TOTP secret encryption | XSalsa20-Poly1305 (authenticated encryption) | MasterKey subkey id=1, context=`encrypt_` (default Encryptor) |
| Recovery code hashing  | keyed BLAKE2b                                | MasterKey subkey id=3, context=`rcvrycod`                     |
| Audit chain integrity  | keyed BLAKE2b                                | MasterKey via audit context                                   |
| Master key derivation  | `sodium_crypto_kdf_derive_from_key`          | Single `PULSAR_MASTER_KEY` env var                            |

**Exception:** The Social SSO extension uses OpenSSL for JWT signature verification (RS256, ES256) inside a scoped `JwtSignatureDriverInterface` implementation. This is an extension-scoped dependency; the core framework remains libsodium-only.
