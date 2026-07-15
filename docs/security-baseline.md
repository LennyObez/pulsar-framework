# Security baseline

Pulsar ships secure defaults for session management, CSRF protection, security headers, cryptographic primitives, and audit logging. All security features are configured via the `SecurityConfig` DTO loaded from `config/security.php`.

## Configuration

### SecurityConfig

Top-level DTO composing all security sub-configs:

```php
// config/security.php
return [
    'session' => [
        'cookie_name'   => 'PULSAR_SESSION',
        'lifetime'      => 7200,
        'cookie_httponly'=> true,
        'cookie_secure'  => true,
        'cookie_samesite'=> 'Strict',
        'regenerate_on_privilege_change' => true,
    ],
    'csrf' => [
        'enabled'         => true,
        'token_length'    => 32,
        'header_name'     => 'X-CSRF-Token',
        'form_field_name' => '_csrf_token',
    ],
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'X-XSS-Protection'      => '0',
        'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
    ],
    'rate_limit' => [
        'max_attempts'   => 60,
        'window_seconds' => 60,
    ],
];
```

Environment variable overrides:

| Variable              | Overrides             |
| --------------------- | --------------------- |
| `SESSION_COOKIE_NAME` | `session.cookie_name` |

### Config DTOs

| Class                   | Responsibility                       |
| ----------------------- | ------------------------------------ |
| `SecurityConfig`        | Top-level; composes sub-configs      |
| `SessionConfig`         | Session cookie and lifetime settings |
| `CsrfConfig`            | CSRF toggle, token length, names     |
| `SecurityHeadersConfig` | Header key-value pairs               |
| `RateLimitConfig`       | Rate limit window and max attempts   |

All DTOs are `readonly` classes with `fromArray()` factory methods, following the same pattern as `AppConfig` and `ObservabilityConfig`.

## Session management

### SessionInterface

```php
interface SessionInterface
{
    public function start(): void;
    public function isStarted(): bool;
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function has(string $key): bool;
    public function remove(string $key): void;
    public function id(): string;
    public function regenerate(bool $deleteOldSession = true): void;
    public function destroy(): void;
    public function all(): array;
}
```

### Session

`Pulsar\Security\Session\Session` wraps PHP's native session functions with security settings from `SessionConfig`:

- **HttpOnly cookies** - prevents JavaScript access to session cookies
- **Secure cookies** - cookies only sent over HTTPS
- **SameSite** - mitigates cross-site request forgery via cookie policy
- **Strict mode** - rejects uninitialized session IDs
- **Cookies only** - prevents session ID leakage via URL parameters

```php
$session = new Session($sessionConfig);
$session->start();
$session->set('user_id', 42);
$userId = $session->get('user_id');  // 42
$session->regenerate();              // New session ID, same data
$session->destroy();                 // Clears all data
```

Call `regenerate()` after privilege escalation (login, role change) to prevent session fixation attacks.

## CSRF protection

### Synchronizer token pattern

Pulsar uses the synchronizer token pattern: a random token is generated and stored server-side in the session. State-changing requests must include the token for validation.

### CsrfTokenManagerInterface

```php
interface CsrfTokenManagerInterface
{
    public function generate(): string;
    public function getToken(): string;
    public function validate(string $submittedToken): bool;
    public function rotate(): string;
}
```

### CsrfTokenManager

Generates hex-encoded tokens via `random_bytes()`, stores them in the session, and validates using constant-time `hash_equals()` comparison.

```php
$manager = new CsrfTokenManager($session, $csrfConfig);

$token = $manager->getToken();       // Get current or generate new
$valid = $manager->validate($token);  // true
$manager->rotate();                   // Invalidate old, generate new
```

### CsrfMiddleware

Automatically validates CSRF tokens on state-changing HTTP methods.

**Safe methods** (pass through without validation):

- `GET`, `HEAD`, `OPTIONS`

**Validated methods** (require a valid CSRF token):

- `POST`, `PUT`, `PATCH`, `DELETE`

**Token extraction** (checked in order, first match wins):

1. Request header (`X-CSRF-Token` by default)
2. POST form field (`_csrf_token` by default)

**On failure**, the middleware returns a 403 JSON response:

```json
{
  "error": "Forbidden",
  "message": "CSRF token missing."
}
```

```json
{
  "error": "Forbidden",
  "message": "CSRF token invalid."
}
```

**Disabling CSRF**: Set `csrf.enabled` to `false` in `config/security.php`. The middleware will pass all requests through without validation.

### HTML form usage

Include the token as a hidden field:

```html
<form method="POST" action="/submit">
  <input type="hidden" name="_csrf_token" value="{{ csrfToken }}" />
  <!-- form fields -->
</form>
```

### AJAX usage

Include the token in a request header:

```js
fetch('/api/data', {
  method: 'POST',
  headers: {
    'X-CSRF-Token': token,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify(data),
});
```

## Security headers

### SecurityHeadersMiddleware

Applies configured HTTP security headers to every response. Add this middleware globally to ensure all responses include security headers, including error responses.

```php
$middleware = new SecurityHeadersMiddleware($headersConfig);
```

### Minimum default headers

Pulsar ships with five security headers enabled by default. These represent the minimum baseline and are applied to every response when `SecurityHeadersMiddleware` is registered:

| Header                   | Default Value                              | Purpose                                                         |
| ------------------------ | ------------------------------------------ | --------------------------------------------------------------- |
| `X-Content-Type-Options` | `nosniff`                                  | Prevents MIME-type sniffing attacks                             |
| `X-Frame-Options`        | `DENY`                                     | Prevents clickjacking by blocking iframe embedding              |
| `Referrer-Policy`        | `strict-origin-when-cross-origin`          | Controls referrer information leakage                           |
| `X-XSS-Protection`       | `0`                                        | Disables legacy XSS auditors that can introduce vulnerabilities |
| `Permissions-Policy`     | `camera=(), microphone=(), geolocation=()` | Restricts access to sensitive browser APIs                      |

These defaults protect against common attack vectors without requiring any configuration.

### Override mechanism

All security headers are configurable via `config/security.php`. The `headers` key accepts an associative array of header name to value pairs. You can override defaults, add new headers, or remove headers by setting them to an empty string:

```php
// config/security.php
return [
    // ...
    'headers' => [
        // Override a default
        'X-Frame-Options'        => 'SAMEORIGIN',

        // Add new headers
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy'   => "default-src 'self'",
        'Permissions-Policy'        => 'camera=(), microphone=(), geolocation=()',

        // Keep the defaults you want
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
    ],
];
```

The `SecurityHeadersConfig` DTO is a readonly object constructed from this array via `fromArray()`.

#### Literal headers vs. structured blocks

The `headers` array also accepts structured sub-blocks (`csp`, `hsts`, `cross_origin`, `permissions_policy`, `nel`) that are typed and validated — these are the recommended way to configure CSP, HSTS, Cross-Origin isolation, Permissions-Policy, and NEL.

When a **literal** `Strict-Transport-Security` or `Permissions-Policy` key is set alongside its structured block, the **literal wins** — "what you write is what's emitted". To make the override explicit rather than silent, Pulsar logs a one-time boot warning (`component: security.headers`) whenever a literal shadows an active structured block with a different value. A literal `Strict-Transport-Security` is still emitted **only over HTTPS** (RFC 6797 §7.2 forbids HSTS over plaintext HTTP), exactly like the structured `hsts` block.

### HSTS recommendation

For production deployments served over HTTPS, add the `Strict-Transport-Security` (HSTS) header to instruct browsers to only connect via HTTPS:

```php
'headers' => [
    // ... other headers ...
    'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
],
```

| Directive           | Description                                                   |
| ------------------- | ------------------------------------------------------------- |
| `max-age=31536000`  | Browsers remember HTTPS-only for 1 year (recommended minimum) |
| `includeSubDomains` | Apply the policy to all subdomains                            |
| `preload`           | Optional: submit to the HSTS preload list for browser vendors |

Alternatively, use the structured `hsts` block, which is typed and validated. Note the key spelling differs from the literal header directive — the config key is **`include_sub_domains`** (snake_case), while the emitted directive is `includeSubDomains` (the RFC 6797 token):

```php
'headers' => [
    'hsts' => [
        'enabled'             => true,
        'max_age'            => 63072000,
        'include_sub_domains' => true,  // emitted as `includeSubDomains`
        'preload'             => true,
    ],
],
```

A literal `Strict-Transport-Security` (e.g. with `preload`) overrides this structured block — see [Literal headers vs. structured blocks](#literal-headers-vs-structured-blocks).

#### TLS terminated at the edge

When a CDN or reverse proxy (CloudFront, nginx, …) terminates TLS and emits HSTS
itself — on every response, including static assets and error pages that never
reach PHP — the application must **not** emit its own `Strict-Transport-Security`
header, or the response carries a duplicate. Leave `enabled => false` and set
`emitted_at_edge => true`:

```php
'headers' => [
    'hsts' => [
        'enabled'         => false,  // app emits no header (edge already does)
        'emitted_at_edge' => true,   // but the deployment IS HTTPS-only
    ],
],
```

`emitted_at_edge` changes **only** the security-posture assessment: the
`https_enforced` and `hsts_enabled` checks are satisfied, so a correct
edge-terminated deployment is no longer reported as a HTTP-only violation on
every request. It never causes the app to emit the header.

**Do not enable HSTS** (app-side) unless all of the following are true:

- Your domain is served exclusively over HTTPS.
- All subdomains (if using `includeSubDomains`) also support HTTPS.
- You are prepared to maintain HTTPS for at least the `max-age` duration.

HSTS is not included in the defaults because it requires TLS to be correctly configured at the reverse proxy level. Enabling it without HTTPS will lock users out of the site.

### Recommended production configuration

For production environments behind a TLS-terminating reverse proxy, the recommended header set is:

```php
'headers' => [
    'X-Content-Type-Options'        => 'nosniff',
    'X-Frame-Options'               => 'DENY',
    'Referrer-Policy'               => 'strict-origin-when-cross-origin',
    'Strict-Transport-Security'     => 'max-age=31536000; includeSubDomains',
    'Content-Security-Policy'       => "default-src 'self'",
    'Permissions-Policy'            => 'camera=(), microphone=(), geolocation=()',
    'X-Permitted-Cross-Domain-Policies' => 'none',
],
```

### Pipeline order

Place `SecurityHeadersMiddleware` as the **outermost** middleware so headers are applied even when inner middleware short-circuits (e.g., CSRF returning 403):

```
Request → SecurityHeaders → CSRF → RateLimit → Handler
                                                  ↓
Response ← SecurityHeaders ← CSRF ← RateLimit ← Response
```

## Password hashing

Pulsar uses **Argon2id** (via PHP's built-in `password_hash()`) with OWASP 2024 recommended defaults:

| Parameter    | Default | Floor   | Rationale                             |
| ------------ | ------- | ------- | ------------------------------------- |
| `memoryCost` | 19456   | 15360   | 19 MiB resists GPU/ASIC attacks       |
| `timeCost`   | 2       | 2       | Minimum iterations per OWASP guidance |
| `threads`    | 1       | &mdash; | Single-threaded for consistent timing |

The `PasswordHasher` constructor rejects parameters below the floor values with `InvalidArgumentException`. Test environments may bypass validation with `allowWeakParameters: true`.

See [Authentication > Password hashing](authentication.md#password-hashing) for usage examples.

## Cryptographic primitives

All crypto operations use **libsodium** (bundled with PHP 7.2+). No third-party dependencies.

### Hmac

Static utility for BLAKE2b keyed hashing via `sodium_crypto_generichash`:

```php
use Pulsar\Security\Crypto\Hmac;

$hex  = Hmac::computeHex('message', $key);   // 64-char hex string
$raw  = Hmac::compute('message', $key);       // 32-byte binary

$valid = Hmac::verifyHex('message', $hex, $key);  // true
$valid = Hmac::verify('message', $raw, $key);      // true
```

Key must be at least `SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN` (16 bytes).

### MasterKey

Loads a 32-byte master key from the `PULSAR_MASTER_KEY` environment variable (hex-encoded, 64 characters). Derives purpose-specific subkeys via `sodium_crypto_kdf_derive_from_key`:

```php
use Pulsar\Security\Crypto\MasterKey;

$masterKey = MasterKey::fromEnvironment();
$subKey    = $masterKey->deriveSubKey(subKeyId: 1, context: 'encrypt_');
```

- **subKeyId**: integer identifier for the derived key (different IDs produce different keys)
- **context**: exactly 8-byte context string (throws `InvalidArgumentException` if not exactly 8 bytes)
- `__debugInfo()` returns `[REDACTED]` to prevent accidental key leakage in logs/dumps

### Encryptor

Authenticated encryption via `sodium_crypto_secretbox` (XSalsa20 stream cipher + Poly1305 MAC):

```php
use Pulsar\Security\Crypto\Encryptor;

$encryptor  = Encryptor::fromMasterKey($masterKey);
$ciphertext = $encryptor->encrypt('sensitive data');  // base64(nonce || ciphertext)
$plaintext  = $encryptor->decrypt($ciphertext);       // 'sensitive data'
```

- Fresh 24-byte random nonce per encryption
- Ciphertext format: `base64_encode(nonce || ciphertext+MAC)`
- Tamper detection: any modification to the ciphertext causes decryption to fail with `SecurityException`
- Uses derived subkey (id=1, context=`encrypt_`)

### SessionEncryption

Session payloads use a different cipher than `Encryptor`. `SessionEncryption` uses `sodium_crypto_aead_xchacha20poly1305_ietf` (XChaCha20-Poly1305 AEAD) with Additional Authenticated Data (AAD) that binds the ciphertext to the session context:

- **AAD**: `session_id|handler_type|domain` -- prevents payload transplant attacks between sessions or handlers
- **Key**: derived subkey (id=3, context=`session_`)
- **Ciphertext format**: `base64(key_id(8 bytes) || nonce(24 bytes) || ciphertext+tag)`
- **Key rotation**: the `key_id` header identifies which key encrypted the payload, enabling transparent decryption during key rotation windows

The general `Encryptor` uses `sodium_crypto_secretbox` (XSalsa20-Poly1305) without AAD, suitable for context-free encryption. `SessionEncryption` requires AAD binding to prevent cross-session payload injection.

### Key management

1. Generate a master key: `php -r "echo bin2hex(random_bytes(32));"`
2. Set the environment variable: `PULSAR_MASTER_KEY=<64-char-hex>`
3. The kernel automatically registers `MasterKey`, `Encryptor`, and `AuditLogger` when the key is present
4. If `PULSAR_MASTER_KEY` is not set, crypto-dependent services are not registered; session, CSRF, and security headers still function

## Kernel integration

The kernel boot pipeline registers security services automatically:

```
boot() →
  1. ConfigManager::load() (app + observability + security)
  2. registerConfigServices()
  3. createLogger()
  4. createExceptionHandler()
  5. createSecurityServices()      ← security layer
  6. Extension register phase
  7. Extension boot phase
```

`createSecurityServices()` registers:

| Service                     | Container ID                          |
| --------------------------- | ------------------------------------- |
| `Session`                   | `SessionInterface`                    |
| `CsrfTokenManager`          | `CsrfTokenManagerInterface`           |
| `CsrfMiddleware`            | `CsrfMiddleware::class`               |
| `SecurityHeadersMiddleware` | `SecurityHeadersMiddleware::class`    |
| `MasterKey`                 | `MasterKey::class` (if key present)   |
| `Encryptor`                 | `Encryptor::class` (if key present)   |
| `AuditLogger`               | `AuditLogger::class` (if key present) |

## SecurityException

All security errors throw `Pulsar\Security\Exception\SecurityException` with descriptive factory methods:

| Factory Method              | When                                      |
| --------------------------- | ----------------------------------------- |
| `csrfTokenMissing()`        | State-changing request without CSRF token |
| `csrfTokenInvalid()`        | CSRF token fails validation               |
| `sessionNotStarted()`       | Session operation before `start()`        |
| `sessionStartFailed()`      | PHP `session_start()` returns false       |
| `masterKeyMissing()`        | `PULSAR_MASTER_KEY` env var not set       |
| `masterKeyInvalid()`        | Master key not exactly 32 bytes           |
| `encryptionFailed()`        | `sodium_crypto_secretbox` fails           |
| `decryptionFailed()`        | Ciphertext tampered or wrong key          |
| `auditIntegrityViolation()` | Audit entry HMAC verification fails       |
| `auditWriteFailed()`        | Audit file sink cannot write              |

## Security posture preflight

At the end of boot, `SecurityPostureWiring` runs a preflight that evaluates the
deployment's security posture against the fully wired container and produces a
`SecurityPostureReport` of per-control items, each `OK`, `DEGRADED`, or `FAIL`
with a reason and a fix. It fails **loud** instead of letting a control run
silently inert — most importantly, a security feature disabled by a missing
binding (e.g. a captcha whose single-use replay cache is unbound because
`TaggedCacheInterface` is missing) is reported as a `FAIL`, not dropped.

Controls evaluated: debug mode, CSRF protection, HTTPS/HSTS, master key
presence and strength, session encryption, session cookie flags
(`Secure`/`HttpOnly`/`SameSite`), baseline security headers, and every
security feature flagged inert by the wiring-contract detector. Outside
production, production-only weaknesses surface as `DEGRADED` warnings rather
than failures, so they remain visible without breaking local development.

### Inspecting the posture

```bash
php bin/pulsar security:check
```

Prints every control with its status and fix and exits non-zero when any
control failed, so it doubles as a CI / pre-start deployment gate. The same
report is surfaced through the health endpoint and `health:check` as the
`security_posture` check (`UNHEALTHY` on failure, `DEGRADED` on warning).

### Enforcement

Enforcement is opt-in and ops-controlled, so the default stays backward-safe
(report only, never abort boot):

| Environment variable                  | Effect                                                                                           |
| ------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `PULSAR_SECURITY_POSTURE_ENFORCE`     | `true` → in production, blocking items abort boot                                                |
| `PULSAR_SECURITY_POSTURE_STRICT`      | `true` → `DEGRADED` items block too (not only `FAIL`)                                            |
| `PULSAR_SECURITY_POSTURE_LOG_AT_BOOT` | `true`/`false` → force the boot advisory log (default: on outside production, off in production) |

When enforcement is enabled and running in production, blocking items throw
`SecurityPostureException` during boot rather than starting the application in
a weakened state.

### Boot-time logging

Security posture is a property of the **configuration** — it cannot change
between two requests in the same process. Under a per-request SAPI (PHP-FPM,
where each request is a fresh boot), logging it on every boot would repeat an
unchanging line on every request and bury real incidents. So the advisory boot
log is **off in production by default** (set `PULSAR_SECURITY_POSTURE_LOG_AT_BOOT=true`
to force it on); the posture is still surfaced through `security:check`, the
`/health` endpoint, and boot enforcement. When it does log, it uses `warning`
(a configuration state the operator may have chosen deliberately), never
`error` — reserving `error` for events keeps error-level alerting actionable.
