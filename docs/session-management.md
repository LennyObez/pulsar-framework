# Session Management

Pulsar's session management provides pluggable handlers, encryption at rest, session validators, flash messages, and concurrent session control - designed for regulated, mission-critical deployments.

## Architecture

```
SessionMiddleware (per-request lifecycle)
  └─ SessionManager (orchestrator, implements SessionInterface)
       ├─ Handler (storage backend)
       │   ├─ FileHandler     - file-based, development/single-server
       │   ├─ DatabaseHandler  - PDO-backed, regulated-recommended
       │   ├─ RedisHandler     - Redis-backed, regulated-recommended
       │   ├─ CookieHandler    - encrypted stateless, small payloads only
       │   └─ ArrayHandler     - in-memory, testing only
       ├─ SessionEncryption (AEAD via Keyring)
       ├─ Validators
       │   ├─ UserAgentValidator
       │   ├─ RemoteAddressValidator
       │   └─ FingerprintValidator
       └─ FlashBag (single-use messages)
```

## Quick start

Session management is configured in `config/security.php` under the `session` key. The default configuration uses file-based storage with encryption enabled.

```php
// Reading session data (in a controller or service)
public function dashboard(SessionInterface $session): Response
{
    $userId = $session->get('user_id');
    // ...
}

// Flash messages
public function login(FlashBag $flash): Response
{
    $flash->set('success', 'Welcome back!');
    return Response::redirect('/dashboard');
}

public function dashboard(FlashBag $flash): Response
{
    $message = $flash->get('success'); // consumed after this read
    // ...
}
```

## Handler selection guide

| Handler      | Use Case                   | Concurrency        | Listing | Scaling       |
| ------------ | -------------------------- | ------------------ | ------- | ------------- |
| **Redis**    | Production, regulated      | Yes (Lua)          | Yes     | Horizontal    |
| **Database** | Production, regulated      | Yes (transactions) | Yes     | Horizontal    |
| **File**     | Development, single-server | No                 | No      | Single server |
| **Cookie**   | Stateless APIs, small data | No                 | No      | Inherent      |
| **Array**    | Testing only               | No                 | No      | N/A           |

### Regulated deployments

For banking, healthcare, and legal workloads, use **Redis** (primary) or **Database** (alternative). These handlers support:

- Concurrent session limits (race-safe enforcement)
- Session listing and revocation per user
- Server-side storage with encryption at rest
- Horizontal scaling across multiple servers

### Handler capability matrix

| Capability                 | File       | Database   | Redis     | Cookie      | Array |
| -------------------------- | ---------- | ---------- | --------- | ----------- | ----- |
| Persistent storage         | Yes        | Yes        | Yes       | Client-side | No    |
| Concurrency control        | No         | Yes        | Yes       | No          | No    |
| Session listing/revocation | No         | Yes        | Yes       | No          | No    |
| Horizontal scaling         | No         | Yes        | Yes       | Yes         | No    |
| Garbage collection         | File mtime | SQL DELETE | TTL-based | N/A         | N/A   |

## Configuration reference

```php
// config/security.php
'session' => [
    // Handler: 'file', 'database', 'redis', 'cookie', 'array'
    'handler' => 'file',

    // Cookie settings
    'cookie_name' => 'PULSAR_SESSION',
    'lifetime' => 7200,           // seconds
    'cookie_httponly' => true,     // prevent JavaScript access
    'cookie_secure' => true,      // HTTPS only
    'cookie_samesite' => 'Strict', // CSRF protection
    'cookie_path' => '/',
    'cookie_domain' => '',

    // Security
    'regenerate_on_privilege_change' => true,
    'encryption' => true,          // AEAD encryption via Keyring
    'max_concurrent_sessions' => 3, // per user (Redis/DB only)

    // File handler
    'save_path' => '',             // defaults to sys_get_temp_dir()

    // Garbage collection
    'gc_probability' => 1,
    'gc_divisor' => 100,

    // Cookie handler limits
    'cookie_max_payload_size' => 2048, // bytes, hard cap 4096
    'cookie_replay_window' => 86400,   // seconds

    // Validators
    'validators' => [
        'user_agent' => [
            'enabled' => true,
            'mode' => 'normalized', // 'normalized' or 'strict'
        ],
        'remote_address' => [
            'enabled' => false,
            'mode' => 'subnet',     // 'subnet' or 'strict'
            'ipv4_mask' => 24,
            'ipv6_mask' => 48,
        ],
        'fingerprint' => [
            'enabled' => false,
            'attributes' => ['accept_language', 'accept_encoding'],
        ],
    ],
],
```

## Session encryption

When `encryption` is enabled and `PULSAR_MASTER_KEY` is set, all session data is encrypted at rest using AEAD (XChaCha20-Poly1305) via the central Keyring service.

### How it works

- **Algorithm**: XChaCha20-Poly1305 (libsodium AEAD)
- **AAD**: Session ID, handler type, and domain are bound as Additional Authenticated Data - preventing payload transplant between sessions or handlers
- **Key derivation**: Session encryption key is derived from the master key using KDF with sub-key ID 3 and context `session_`
- **Key rotation**: Each encrypted payload includes a `key_id` header. On rotation, old keys decrypt existing sessions while new keys encrypt on write

### Key rotation

1. Set `PULSAR_MASTER_KEY_PREVIOUS` to the old key
2. Set `PULSAR_MASTER_KEY` to the new key
3. Existing sessions decrypt with the previous key and re-encrypt with the new key on next write
4. After all sessions expire, remove `PULSAR_MASTER_KEY_PREVIOUS`

## Validators

### User agent validator (enabled by default)

Detects session hijacking by comparing the user agent between requests.

**Normalized mode** (default): Extracts stable browser tokens (name + major version) and compares them. Tolerates minor UA variations from extension updates or embedded browsers.

**Strict mode**: Exact string comparison of the full user agent.

### Remote address validator (disabled by default)

Validates that the client IP matches the session's original IP.

**Subnet mode** (default when enabled): Compares IPs at the subnet level (IPv4 /24, IPv6 /48) to tolerate mobile network changes and corporate proxies.

**Strict mode**: Exact IP match for high-security contexts.

### Fingerprint validator (disabled by default)

HMAC-based fingerprint of configurable request attributes. Default attributes: Accept-Language + Accept-Encoding. Privacy-aware: no canvas fingerprinting, font enumeration, or tracking attributes.

## Flash messages

Flash messages are single-use session data that persist for exactly one request.

```php
// Set a flash message (available on next request)
$flash->set('status', 'Profile updated');

// Get and consume (returns null on second read)
$message = $flash->get('status');

// Peek without consuming
$message = $flash->peek('status');

// Keep for one more request
$flash->keep('status');

// Get all and clear
$messages = $flash->all();
```

## Concurrent session limits

Redis and Database handlers support limiting active sessions per user. Default: 3 concurrent sessions.

- **Redis**: Uses atomic Lua scripts for race-safe enforcement
- **Database**: Uses transactional counting for race-safe enforcement
- **File/Cookie/Array**: Not supported (no server-side session index)

When the limit is exceeded, `SecurityException::sessionConcurrencyExceeded()` is thrown.

## Session fixation protection

Session IDs are regenerated on privilege changes (login, role escalation) when `regenerate_on_privilege_change` is enabled (default: true). This is a non-configurable security requirement for regulated deployments.

## Cookie handler

The cookie handler provides stateless sessions via encrypted cookies. Guarantees: **confidentiality + integrity + bounded lifetime**. Replay prevention is best-effort.

### Limitations

- No server-side state - cannot revoke or list sessions
- No concurrency control
- Size constraints: configurable max (default 2KB), hard cap 4KB
- Replay prevention is best-effort (issued_at timestamp validation)

If you need strict replay prevention, use Redis or Database handler instead.
