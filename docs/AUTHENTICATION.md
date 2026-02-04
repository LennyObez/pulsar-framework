# Authentication

Pulsar provides a guard-based authentication system with lazy identity resolution, session and token guards, password hashing, and two-factor authentication (TOTP + recovery codes). All auth services are configured via the `auth` section of `config/security.php` and registered automatically in the Kernel boot pipeline.

## Architecture

### Hybrid Identity Resolution

Authentication uses a two-tier approach to avoid paying the cost of full authentication on every request:

1. **Global middleware** (`AuthenticationMiddleware`) runs on every request but only performs cheap work — creating a `SecurityContext` wrapper and setting a default `AnonymousIdentity`.
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

// Login — stores identity and regenerates session ID (prevents fixation)
$guard->login($identity);

// Authenticate — reads identity from session
$resolved = $guard->authenticate($request); // ?IdentityInterface

// Update identity without session regeneration (e.g., after 2FA verification)
$guard->updateIdentity($verifiedIdentity);

// Logout — removes identity and regenerates session ID
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

### Custom Guards

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

## Password Hashing

Argon2id password hashing via PHP's built-in functions:

```php
$hasher = new PasswordHasher();

$hash = $hasher->hash('secret-password');
$valid = $hasher->verify('secret-password', $hash);  // true
$rehash = $hasher->needsRehash($hash);                // false (fresh hash)
```

Custom Argon2id options:

```php
$hasher = new PasswordHasher([
    'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
    'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
    'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
]);
```

## Two-Factor Authentication

### Overview

Pulsar includes TOTP (RFC 6238) generation and verification with recovery codes as a fallback. The 2FA system is optional and controlled via config.

### Setup Flow

```php
$manager = $container->get(TwoFactorManagerInterface::class);

// 1. Begin setup — generates secret, provisioning URI, and recovery codes
$setup = $manager->beginSetup($identity);
// Returns:
// [
//     'secret' => '<binary>',
//     'secret_base32' => 'JBSWY3DPEHPK3PXP...',
//     'provisioning_uri' => 'otpauth://totp/Pulsar:user@example.com?...',
//     'recovery_codes' => ['A3F2-9B4C', '7D1E-F056', ...],
// ]

// 2. Display QR code from provisioning_uri, show recovery codes to user

// 3. Confirm setup — user enters code from authenticator app
$confirmed = $manager->confirmSetup($setup['secret'], $userCode);
```

### Verification Flow

```php
// During login, after password verification:
$valid = $manager->verifyCode($secret, $userCode);

// Recovery code fallback:
$index = $manager->verifyRecoveryCode($userCode, $storedCodes);
// Returns matched index (0-based) or -1 if invalid
```

### Identity Status Transitions

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

### TOTP Internals

`TotpGenerator` implements RFC 6238 (TOTP) built on RFC 4226 (HOTP):

- Default: 6-digit codes, 30-second period, SHA-1 algorithm
- `TotpVerifier` checks codes within a configurable time window (default ±1 period) to account for clock drift
- All comparisons use constant-time operations

### Recovery Codes

- Generated in `XXXX-XXXX` format (uppercase hex)
- Default: 8 codes per setup
- Verification is case-insensitive with constant-time comparison
- Each code is single-use — applications should mark used codes

## Middleware

### AuthenticationMiddleware (Global)

Runs on every request. Attaches `SecurityContext` and default `AnonymousIdentity` to the request attributes. Does **not** trigger full authentication:

```php
// Registered automatically as global middleware by Kernel
// Sets: _security_context (SecurityContext), _identity (AnonymousIdentity)
```

### AuthorizationMiddleware (Route-level)

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

See [Authorization](AUTHORIZATION.md) for details on the Gate and permission model.

### TwoFactorMiddleware (Route-level)

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

## Kernel Boot Integration

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
