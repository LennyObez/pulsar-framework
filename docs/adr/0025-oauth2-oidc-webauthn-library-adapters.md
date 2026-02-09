# ADR-0025: OAuth2/OIDC + WebAuthn Library Adapters

## Status

Accepted

## Context

Pulsar targets regulated domains (banking, healthcare, legal) that require enterprise SSO via OAuth2/OIDC and strong authentication via WebAuthn/Passkeys. PSD2 Strong Customer Authentication mandates multi-factor authentication for financial services.

These are security-critical protocols with extensive RFCs (6749, 7636, 8414, 8252), subtle edge cases, and known attack vectors. Rolling custom implementations is a liability. ADR-0006 (libsodium-only cryptography) explicitly acknowledges that "applications needing specific algorithms (RSA for JWT signing) must use external packages."

The Social SSO extension (`pulsar/social-sso`) already provides OAuth2 _client-side_ flows but not server-side provider capability. Plan 09 adds server-side OAuth2/OIDC provider and WebAuthn authenticator support as two new extensions.

## Decision Drivers

1. **Protocol correctness.** OAuth2/OIDC and WebAuthn have complex state machines where implementation errors create security vulnerabilities. Audited libraries reduce this risk.
2. **Supply chain minimalism.** Pulsar's crypto policy (ADR-0006) prefers minimal external dependencies. Libraries must be well-maintained, MIT-licensed, and widely deployed.
3. **Swappability.** Pulsar defines its own port interfaces; library adapters are implementation details that can be replaced without affecting consumers.
4. **Keyring integration.** All signing key material must flow through `KeyRingInterface` (cross-plan invariant Finding B). Libraries receive keys from Keyring, never manage keys independently.
5. **PHP 8.5 compatibility.** All selected libraries must support PHP 8.5.

## Decision

### Contract-First Architecture

Pulsar defines port interfaces (contracts) as its public API surface. Default implementations wrap proven, audited libraries behind these ports. The libraries are extension-level Composer dependencies, not framework-level dependencies.

### Selected Libraries

#### OAuth2/OIDC Server: `league/oauth2-server`

- **Package:** `league/oauth2-server` ^9.0
- **License:** MIT
- **Maintainer:** The PHP League (active, 7000+ GitHub stars)
- **RFC coverage:** OAuth2 (RFC 6749), PKCE (RFC 7636), Token Introspection (RFC 7662), Token Revocation (RFC 7009)
- **Rationale:** Most mature and widely deployed PHP OAuth2 server implementation. Battle-tested in production across thousands of applications. Clean repository interface pattern that maps naturally to Pulsar's port contracts.

#### WebAuthn: `web-auth/webauthn-lib`

- **Package:** `web-auth/webauthn-lib` ^5.0
- **License:** MIT
- **Maintainer:** Spomky-Labs (active, FIDO Alliance member contributions)
- **Coverage:** WebAuthn Level 2/3, registration and authentication ceremonies, attestation formats (none, packed, fido-u2f, android-key, apple), resident credentials (passkeys), authenticator counter validation
- **Rationale:** Most comprehensive PHP WebAuthn implementation. Maintained by a recognized contributor to the FIDO ecosystem. Supports all attestation formats and ceremony types needed for passkey flows.

#### JOSE (JWT/JWK/JWA): `web-token/jwt-framework`

- **Package:** `web-token/jwt-framework` ^4.0
- **License:** MIT
- **Maintainer:** Spomky-Labs (same team as WebAuthn library)
- **Coverage:** JWS (RFC 7515), JWK (RFC 7517), JWA (RFC 7518), JWT (RFC 7519), JWKS endpoint support
- **Algorithms:** RS256, RS384, RS512, ES256, ES384, ES512, EdDSA
- **Rationale:** Full JOSE implementation supporting all algorithms needed for OIDC ID tokens and JWKS endpoints. Same maintainer as the WebAuthn library ensures version compatibility. Preferred over `firebase/php-jwt` for its complete JWK/JWKS support and algorithm coverage.

### Extension Structure

Two new extensions following the pattern established by `pulsar/social-sso`:

```
extensions/oauth2/
  pulsar.json          # trust_tier: core
  composer.json         # requires league/oauth2-server, web-token/jwt-framework
  src/
    Contract/           # Port interfaces (#[Api])
    Adapter/            # Library adapter implementations (#[Internal])
    Grant/              # Authorization code, client credentials, refresh token
    Token/              # Token storage, rotation, replay detection
    Oidc/               # ID tokens, UserInfo, Discovery
    Client/             # Client entity, repository
    Consent/            # Consent entity, repository
    Config/             # Configuration DTOs
    Exception/          # Extension exceptions
    OAuth2Extension.php
    OAuth2ServiceProvider.php

extensions/webauthn/
  pulsar.json          # trust_tier: core
  composer.json         # requires web-auth/webauthn-lib
  src/
    Contract/           # Port interfaces (#[Api])
    Adapter/            # Library adapter implementations (#[Internal])
    Ceremony/           # Registration, authentication
    Authenticator/      # Authenticator entity, management
    Attestation/        # Attestation policy configuration
    PublicKey/          # Credential source, public key storage
    Config/             # Configuration DTOs
    Exception/          # Extension exceptions
    WebAuthnExtension.php
    WebAuthnServiceProvider.php
```

Both extensions declare `"trust_tier": "core"` in their manifests (ADR-0023) since they require `CryptoKeyAccess` for signing key material via Keyring.

## Integration Architecture

### Keyring Integration (Finding B)

Libraries never manage keys independently. Pulsar's adapter layer bridges library key interfaces to `KeyRingInterface`:

- **OAuth2 token signing:** The JOSE library's `JWKSet` is populated from `KeyRingInterface::all()` at boot time. The adapter converts Keyring's raw key bytes into JWK format. Key rotation is handled by Keyring - the adapter re-reads keys on each signing operation.
- **WebAuthn challenges:** Challenge nonces are generated via `random_bytes()` (libsodium-backed on PHP 8.5). HMAC operations for challenge binding use Keyring-derived subkeys.
- **JWKS endpoint:** Public keys are derived from Keyring-managed private keys and served as a JWK Set at `/.well-known/jwks.json`.

The Keyring sub-key derivation context for OAuth2/OIDC signing:

- `pulsar__oauth_sign` - JWT signing key (RSA/EC private key material)

### Replay-Safety and Audit (Finding D)

All security-sensitive operations emit auditable events via `AuditLoggerInterface`:

| Operation               | AuditEvent            | Replay Protection                       |
| ----------------------- | --------------------- | --------------------------------------- |
| Token issuance          | `Authentication`      | Unique `jti` claim, one-time auth codes |
| Token refresh           | `Authentication`      | Refresh token rotation (one-time use)   |
| Token revocation        | `Authentication`      | Idempotent (revoke is safe to replay)   |
| Client registration     | `ConfigurationChange` | Admin-gated, audit-logged               |
| WebAuthn registration   | `Authentication`      | One-time challenge, counter validation  |
| WebAuthn authentication | `Authentication`      | One-time challenge, counter increment   |
| Consent grant/revoke    | `Authorization`       | Timestamped, audit-logged               |

Refresh token replay detection: if a rotated-out refresh token is reused, the entire token family (all tokens issued from the same authorization) is revoked and a `SecurityEvent` audit entry is emitted.

### Session Integration (Plan 08)

OAuth2 authorization code flow uses `SessionManager` for:

- CSRF state parameter storage and verification (via `SessionOAuthStateManager` pattern)
- PKCE code verifier storage during the authorization flow
- User consent state during multi-step authorization
- Login session binding for refresh token issuance

### Guard System Integration

Two new guards integrate with `AuthManagerInterface`:

- **`OAuth2Guard`** - Validates bearer tokens (reference or JWT) from `Authorization: Bearer` headers. Resolves to `IdentityInterface` via token introspection.
- **`WebAuthnGuard`** - Validates WebAuthn assertion results during authentication ceremonies. Works with `SessionGuard` for session binding post-authentication.

### Token Storage Model

| Token Type               | Storage                    | Lifetime             | Binding                               |
| ------------------------ | -------------------------- | -------------------- | ------------------------------------- |
| Access token (reference) | Hashed in repository       | 15 min default       | client + subject + scopes             |
| Access token (JWT)       | Self-contained, not stored | 15 min default       | client + subject + scopes (in claims) |
| Refresh token            | Hashed in repository       | 30 day default       | client + subject + session            |
| Authorization code       | Hashed in repository       | 10 min default       | client + redirect_uri + PKCE verifier |
| ID token                 | Not stored (JWT)           | Matches access token | client + subject + nonce              |

## Alternatives Considered

### Custom OAuth2/OIDC implementation

Building the OAuth2 state machine and token management from scratch. Rejected: the RFC surface area is enormous (6749, 7636, 7662, 7009, 8414, plus OIDC Core/Discovery), implementation errors create direct security vulnerabilities, and no competitive advantage from custom protocol code.

### `firebase/php-jwt` for JOSE

Simpler library with smaller footprint. Rejected: lacks full JWK Set support, JWKS endpoint generation, and algorithm coverage needed for OIDC compliance. The `web-token/jwt-framework` provides complete RFC coverage with the same MIT license.

### Single combined extension

Packaging OAuth2 and WebAuthn in one extension. Rejected: they serve different use cases (OAuth2 for API authorization, WebAuthn for passwordless authentication), have different dependency trees, and should be independently installable.

## Consequences

### Positive

- **Protocol correctness by default.** Audited libraries handle the complex protocol state machines.
- **Swappable implementations.** Port interfaces decouple Pulsar's API from library internals. Alternative adapters (e.g., wrapping a different OAuth2 library) can be shipped without breaking consumers.
- **Consistent key management.** All signing keys flow through Keyring, maintaining the single-key-management principle from ADR-0006.
- **Independent extensions.** Applications needing only OAuth2 or only WebAuthn install only what they need.

### Negative

- **External dependencies.** Three new library dependencies (`league/oauth2-server`, `web-auth/webauthn-lib`, `web-token/jwt-framework`) increase supply chain surface. Mitigated by: MIT-licensed, widely deployed, and isolated to extension-level `composer.json` (not framework core).
- **Adapter maintenance.** Library version upgrades may require adapter updates. Mitigated by: port interfaces insulate consumers from library API changes.
- **RSA/EC key material.** OAuth2/OIDC requires asymmetric keys (RSA/EC) that are outside libsodium's scope. These keys must be managed alongside the sodium master key, extending Keyring's responsibility.

### Neutral

- **Trust tier.** Both extensions are `Core` tier, consistent with their access to cryptographic key material.
- **Existing Social SSO.** The `pulsar/social-sso` extension (OAuth2 client) remains independent. The new `pulsar/oauth2` extension (OAuth2 server) complements it.

## Field Report

_Placeholder - to be filled after operational experience._

## Security Impact

Significant expansion of the framework's security surface:

- **New attack vectors:** Token theft, authorization code interception, redirect URI manipulation, refresh token replay, client impersonation, WebAuthn relay attacks, attestation forgery, ID token substitution.
- **Mitigations:** PKCE mandatory, strict redirect URI matching, refresh token rotation with family revocation, hashed token storage, one-time authorization codes, challenge-response ceremonies, attestation format policies.
- **Threat model required:** A formal threat model document must be completed and reviewed by the Security Red Team before release (see plan acceptance criteria).

## Performance Impact

- **Token issuance:** JWT signing (RS256/ES256) adds ~1-5ms per token issuance. Acceptable for auth endpoints which are not high-frequency hot paths.
- **Token validation:** JWT verification on every authenticated request adds ~0.5-2ms. Reference tokens require a repository lookup instead. Both are within Tier A budget tolerances for authenticated request classes.
- **WebAuthn ceremonies:** Public key operations are CPU-bound but occur only during registration/authentication (not per-request). No hot-path impact.
- **JWKS endpoint:** Cacheable, served from Keyring. No computation per request after initial key derivation.

## Migration / Rollback Plan

**Adoption:**

1. Install `pulsar/oauth2` and/or `pulsar/webauthn` extensions via Composer.
2. Configure via `config/oauth2.php` and `config/webauthn.php` stubs.
3. Register extensions in `pulsar.json`.
4. Run database migrations for token/client/credential storage tables.

**Rollback:**

1. Remove extensions from `pulsar.json`.
2. Remove extension packages via Composer.
3. Drop migration tables (token, client, credential storage).
4. No impact on core framework - extensions are fully self-contained.

## Links

- ADR-0004: Extension-First Architecture with Manifest-Driven Lifecycle
- ADR-0006: Libsodium-Only Cryptography with Master Key Derivation
- ADR-0023: Extension Trust Tiers & Capability Enforcement
- RFC 6749: The OAuth 2.0 Authorization Framework
- RFC 7636: Proof Key for Code Exchange (PKCE)
- RFC 7662: OAuth 2.0 Token Introspection
- RFC 7009: OAuth 2.0 Token Revocation
- OpenID Connect Core 1.0
- OpenID Connect Discovery 1.0
- Web Authentication (WebAuthn) Level 2
- FIDO2: Client to Authenticator Protocol (CTAP)
