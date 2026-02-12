# WebAuthn/Passkeys

Pulsar's WebAuthn extension provides FIDO2-compliant passwordless authentication with passkey support.

## Installation

Add the `pulsar/webauthn` extension to your project:

```bash
composer require pulsar/webauthn
```

Register in your `pulsar.json`:

```json
{
  "extensions": ["pulsar/webauthn"]
}
```

## Quick start

### Configuration

Create `config/webauthn.php`:

```php
return [
    'rp_name' => 'My Application',
    'rp_id' => 'example.com',
    'origin' => 'https://example.com',
    'user_verification' => 'preferred',
    'attestation' => 'none',
    'allowed_formats' => ['none', 'packed'],
    'challenge_ttl_seconds' => 300,
    'timeout' => 60000,
];
```

### Registered endpoints

| Endpoint                               | Method | Description                     |
| -------------------------------------- | ------ | ------------------------------- |
| `/webauthn/register/options`           | POST   | Generate registration options   |
| `/webauthn/register/verify`            | POST   | Verify registration response    |
| `/webauthn/authenticate/options`       | POST   | Generate authentication options |
| `/webauthn/authenticate/verify`        | POST   | Verify authentication response  |
| `/webauthn/authenticators`             | GET    | List user's authenticators      |
| `/webauthn/authenticators/{id}/rename` | PUT    | Rename an authenticator         |
| `/webauthn/authenticators/{id}`        | DELETE | Revoke an authenticator         |

## Registration ceremony

### 1. Request registration options

```javascript
const response = await fetch('/webauthn/register/options', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ username: 'alice' }),
});
const options = await response.json();
```

### 2. Create credential (browser)

```javascript
const credential = await navigator.credentials.create({
  publicKey: options,
});
```

### 3. Verify registration

```javascript
const result = await fetch('/webauthn/register/verify', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    credential: credential,
    challenge: options.challenge,
  }),
});
```

## Authentication ceremony

### 1. Request authentication options

```javascript
const response = await fetch('/webauthn/authenticate/options', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({}), // empty for passkey (discoverable) flow
});
const options = await response.json();
```

### 2. Get assertion (browser)

```javascript
const assertion = await navigator.credentials.get({
  publicKey: options,
});
```

### 3. Verify authentication

```javascript
const result = await fetch('/webauthn/authenticate/verify', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    credential: assertion,
    challenge: options.challenge,
  }),
});
```

## Supported features

### Authenticator types

- **Platform authenticators**: Touch ID, Windows Hello, Face ID (built into the device)
- **Cross-platform authenticators**: Security keys via USB, NFC, or BLE

### Passkeys (resident credentials)

Discoverable credentials that don't require a username. The authenticator stores the user handle and can be used for passwordless login.

### Attestation formats

Initially supports:

- **`none`**: No attestation (default, suitable for most use cases)
- **`packed`**: Self-attestation or attestation from a trusted CA

Additional formats (fido-u2f, android-key, apple) can be added based on demand. The attestation policy is configurable - disallowed formats are rejected.

### Clone detection

The signature counter is validated on each authentication. If the counter does not increase (indicating a cloned authenticator), the ceremony is rejected and a security event is emitted.

## Authenticator management

Users can manage their registered authenticators:

- **List**: View all registered authenticators with type, name, and last-used date
- **Rename**: Update the display name for an authenticator
- **Revoke**: Deactivate an authenticator (maintains audit trail)

## Integration with 2FA

WebAuthn integrates with Pulsar's existing two-factor authentication system as an alternative factor. Users can choose between:

- TOTP (time-based one-time passwords)
- WebAuthn (security keys or passkeys)
- Recovery codes

## Security

### Challenge binding

Challenges are:

- Generated using `random_bytes(32)` (cryptographically secure)
- One-time use (consumed after verification)
- Time-limited (configurable TTL, default 5 minutes)
- Bound to the relying party ID and origin

### Audit trail

All WebAuthn events are logged via the tamper-evident audit chain:

- Registration started, verified, failed
- Authentication started, verified, failed
- Clone detection events
- Authenticator revocation

### Keyring integration

Cryptographic operations use Keyring-managed keys (ADR-0006).

## Extending

### Custom credential storage

Replace the in-memory repository with a persistent implementation:

```php
$container->bind(
    CredentialRepositoryInterface::class,
    DatabaseCredentialRepository::class,
);
```

### Custom authenticator repository

```php
$container->bind(
    AuthenticatorRepositoryInterface::class,
    DatabaseAuthenticatorRepository::class,
);
```

## Architecture

The extension follows the contract-first pattern (ADR-0025):

- **Contracts** (`Contract/`): Port interfaces defining the public API (`#[Api]`)
- **Adapters** (`Adapter/`): Library adapter implementations (`#[Internal]`)
- **Ceremony** (`Ceremony/`): Registration and authentication ceremonies
- **Authenticator** (`Authenticator/`): Authenticator management
- **Attestation** (`Attestation/`): Attestation format policy
- **PublicKey** (`PublicKey/`): Credential source storage

## Related

- [OAuth2/OIDC](oauth2-oidc.md) - authorization server
- [Session Management](session-management.md) - session infrastructure
- [ADR-0025](adr/0025-oauth2-oidc-webauthn-library-adapters.md) - library selection rationale
