# Security Baseline

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
        'cookie_samesite'=> 'Lax',
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

## Session Management

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

## CSRF Protection

### Synchronizer Token Pattern

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

### HTML Form Usage

Include the token as a hidden field:

```html
<form method="POST" action="/submit">
  <input type="hidden" name="_csrf_token" value="{{ csrfToken }}" />
  <!-- form fields -->
</form>
```

### AJAX Usage

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

## Security Headers

### SecurityHeadersMiddleware

Applies configured HTTP security headers to every response. Add this middleware globally to ensure all responses include security headers, including error responses.

```php
$middleware = new SecurityHeadersMiddleware($headersConfig);
```

### Minimum Default Headers

Pulsar ships with three security headers enabled by default. These represent the minimum baseline and are applied to every response when `SecurityHeadersMiddleware` is registered:

| Header                   | Default Value                     | Purpose                                            |
| ------------------------ | --------------------------------- | -------------------------------------------------- |
| `X-Content-Type-Options` | `nosniff`                         | Prevents MIME-type sniffing attacks                |
| `X-Frame-Options`        | `DENY`                            | Prevents clickjacking by blocking iframe embedding |
| `Referrer-Policy`        | `strict-origin-when-cross-origin` | Controls referrer information leakage              |

These defaults protect against common attack vectors without requiring any configuration.

### Override Mechanism

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

The `SecurityHeadersConfig` DTO is a readonly object constructed from this array via `fromArray()`. Headers are applied in the order they appear in the configuration.

### HSTS Recommendation

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

**Do not enable HSTS** unless all of the following are true:

- Your domain is served exclusively over HTTPS.
- All subdomains (if using `includeSubDomains`) also support HTTPS.
- You are prepared to maintain HTTPS for at least the `max-age` duration.

HSTS is not included in the defaults because it requires TLS to be correctly configured at the reverse proxy level. Enabling it without HTTPS will lock users out of the site.

### Recommended Production Configuration

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

### Pipeline Order

Place `SecurityHeadersMiddleware` as the **outermost** middleware so headers are applied even when inner middleware short-circuits (e.g., CSRF returning 403):

```
Request → SecurityHeaders → CSRF → RateLimit → Handler
                                                  ↓
Response ← SecurityHeaders ← CSRF ← RateLimit ← Response
```

## Cryptographic Primitives

All crypto operations use **libsodium** (bundled with PHP 7.2+). No third-party dependencies.

### Hmac

Static utility for BLAKE2b keyed hashing via `sodium_crypto_generichash`:

```php
use Pulsar\Security\Crypto\Hmac;

$hex  = Hmac::computeHex('message', $key);   // 64-char hex string
$raw  = Hmac::compute('message', $key);       // 32-byte binary

$valid = Hmac::verifyHex($hex, 'message', $key);  // true
$valid = Hmac::verify($raw, 'message', $key);      // true
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
- **context**: 8-byte context string (padded or truncated automatically)
- `__debugInfo()` returns `[REDACTED]` to prevent accidental key leakage in logs/dumps

### Encryptor

Authenticated encryption via `sodium_crypto_secretbox` (XSalsa20 stream cipher + Poly1305 MAC):

```php
use Pulsar\Security\Crypto\Encryptor;

$encryptor  = new Encryptor($masterKey);
$ciphertext = $encryptor->encrypt('sensitive data');  // base64(nonce || ciphertext)
$plaintext  = $encryptor->decrypt($ciphertext);       // 'sensitive data'
```

- Fresh 24-byte random nonce per encryption
- Ciphertext format: `base64_encode(nonce || ciphertext+MAC)`
- Tamper detection: any modification to the ciphertext causes decryption to fail with `SecurityException`
- Uses derived subkey (id=1, context=`encrypt_`)

### Key Management

1. Generate a master key: `php -r "echo bin2hex(random_bytes(32));"`
2. Set the environment variable: `PULSAR_MASTER_KEY=<64-char-hex>`
3. The kernel automatically registers `MasterKey`, `Encryptor`, and `AuditLogger` when the key is present
4. If `PULSAR_MASTER_KEY` is not set, crypto-dependent services are not registered; session, CSRF, and security headers still function

## Kernel Integration

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
