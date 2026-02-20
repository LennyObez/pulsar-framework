# OAuth2/OIDC Authorization Server

Pulsar's OAuth2/OIDC extension provides a server-side authorization server with OpenID Connect provider capability.

## Installation

Add the `pulsar/oauth2` extension to your project:

```bash
composer require pulsar/oauth2
```

Register in your `pulsar.json`:

```json
{
  "extensions": ["pulsar/oauth2"]
}
```

## Quick start

### Configuration

Create `config/oauth2.php`:

```php
return [
    'issuer' => 'https://auth.example.com',
    'access_token_ttl' => 900,        // 15 minutes
    'refresh_token_ttl' => 2_592_000,  // 30 days
    'authorization_code_ttl' => 600,   // 10 minutes
    'signing_key_id' => 'oauth_sign',
    'token_format' => 'reference',     // or 'jwt'
];
```

### Registered endpoints

The extension automatically registers:

| Endpoint                            | Method | Description                    |
| ----------------------------------- | ------ | ------------------------------ |
| `/oauth/authorize`                  | GET    | Authorization endpoint         |
| `/oauth/token`                      | POST   | Token endpoint                 |
| `/oauth/introspect`                 | POST   | Token introspection (RFC 7662) |
| `/oauth/revoke`                     | POST   | Token revocation (RFC 7009)    |
| `/.well-known/openid-configuration` | GET    | OIDC Discovery                 |
| `/.well-known/jwks.json`            | GET    | JWKS endpoint                  |
| `/oauth/userinfo`                   | GET    | OIDC UserInfo                  |

## Supported grant types

### Authorization code with PKCE (RFC 7636)

PKCE is **mandatory** - all authorization code requests must include a `code_challenge` with method `S256`. Plain method is rejected.

```
GET /oauth/authorize?
  response_type=code&
  client_id=my-app&
  redirect_uri=https://app.example.com/callback&
  scope=openid+profile+email&
  state=random-csrf-token&
  code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&
  code_challenge_method=S256
```

Exchange the authorization code for tokens:

```
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code&
code=SplxlOBeZQQYbYS6WxSbIA&
redirect_uri=https://app.example.com/callback&
client_id=my-app&
code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk
```

### Client credentials

For machine-to-machine authentication:

```
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&
client_id=service-account&
client_secret=secret&
scope=api.read+api.write
```

### Refresh token

Refresh tokens use one-time rotation - each use issues a new refresh token and invalidates the old one:

```
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token&
refresh_token=tGzv3JOkF0XG5Qx2TlKWIA&
client_id=my-app
```

**Replay detection**: If a previously rotated-out refresh token is reused, the entire token family is revoked as a breach indicator.

## Token storage

| Token Type         | Storage            | Lifetime | Binding                               |
| ------------------ | ------------------ | -------- | ------------------------------------- |
| Access Token       | Hashed (reference) | 15 min   | client + subject + scopes             |
| Refresh Token      | Hashed             | 30 days  | client + subject + session            |
| Authorization Code | Hashed             | 10 min   | client + redirect_uri + PKCE verifier |
| ID Token           | Not stored (JWT)   | 15 min   | client + subject + nonce              |

All token values are hashed before storage. Token values are never stored in plaintext.

## OpenID connect

### ID tokens

ID tokens contain the required OIDC claims:

- `iss` - Issuer identifier
- `sub` - Subject identifier
- `aud` - Audience (client ID)
- `exp` - Expiration time
- `iat` - Issued at time
- `nonce` - Nonce from authorization request (if provided)

Plus scope-derived claims from the `UserClaimsProviderInterface`.

### Scope-to-claim mapping

| Scope     | Claims                                                                               |
| --------- | ------------------------------------------------------------------------------------ |
| `openid`  | `sub`                                                                                |
| `profile` | `name`, `family_name`, `given_name`, `nickname`, `picture`, `gender`, `birthdate`... |
| `email`   | `email`, `email_verified`                                                            |
| `address` | `address`                                                                            |
| `phone`   | `phone_number`, `phone_number_verified`                                              |

### Discovery

The `/.well-known/openid-configuration` endpoint returns the OpenID Provider configuration document.

## Security

### Redirect URI validation

Strict exact match against registered URIs. No wildcards by default. Every redirect URI must be pre-registered with the client.

### Dynamic client registration

Disabled by default. Enabling requires explicit admin policy configuration, audit events, and allowlisted client metadata schemas.

### Keyring integration

All token signing uses Keyring-managed keys (ADR-0006). The JOSE library receives key material from the Keyring - it never manages keys independently.

### Audit trail

All OAuth2 events are logged via the tamper-evident audit chain:

- Token issuance, refresh, and revocation
- Authorization code issuance
- Client authentication (success and failure)
- Refresh token replay detection (security event)

## Extending

### Custom token storage

Replace the in-memory repositories with persistent implementations:

```php
$container->bind(
    AccessTokenRepositoryInterface::class,
    DatabaseAccessTokenRepository::class,
);
```

### Custom claims provider

Implement `UserClaimsProviderInterface` to provide user profile data:

```php
final readonly class AppUserClaimsProvider implements UserClaimsProviderInterface
{
    public function getClaims(string $subjectId, array $scopes): array
    {
        $user = $this->users->find($subjectId);
        return ScopeClaimsMapper::filterClaims($user->toClaimsArray(), $scopes);
    }

    public function getSubjectIdentifier(string $userId, string $clientId): string
    {
        return $userId; // or pairwise identifier
    }
}
```

## Architecture

The extension follows the contract-first pattern (ADR-0025):

- **Contracts** (`Contract/`): Port interfaces defining the public API (`#[Api]`)
- **Adapters** (`Adapter/`): Library adapter implementations (`#[Internal]`)
- **Grants** (`Grant/`): Grant type handlers
- **Token** (`Token/`): Token storage and domain objects
- **OIDC** (`Oidc/`): OpenID Connect components
- **Client** (`Client/`): OAuth2 client management
- **Consent** (`Consent/`): User consent management

## Related

- [WebAuthn/Passkeys](webauthn.md) - passwordless authentication
- [Session Management](session-management.md) - session infrastructure
- [ADR-0025](adr/0025-oauth2-oidc-webauthn-library-adapters.md) - library selection rationale
