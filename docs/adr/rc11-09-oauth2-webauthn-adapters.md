# ADR-RC11-09: OAuth2/OIDC Server + WebAuthn/Passkeys via Library Adapters

## Status

Accepted

## Context

Pulsar targets regulated domains (banking, healthcare, legal) where OAuth2/OIDC authorization and WebAuthn/Passkeys authentication are table-stakes requirements. Implementing these protocols from scratch is infeasible - the specifications are large (RFC 6749, RFC 7636, RFC 7662, RFC 7009, OpenID Connect Core 1.0, WebAuthn Level 2) and security-critical. A single implementation flaw in token signing, PKCE validation, or attestation parsing creates exploitable vulnerabilities.

Pulsar already has precedent for vendor-neutral protocol integration: the `social-sso` extension (`extensions/social-sso/`) wraps OpenSSL JWT operations behind `JwtSignatureDriverInterface` and delegates JWKS fetching to a pluggable `JwksFetcher`. This ADR extends that pattern to OAuth2 server-side authorization and WebAuthn server-side authentication.

### Constraints

- **Extension-first architecture (ADR-0004).** OAuth2 and WebAuthn ship as extensions under `extensions/`, not in `src/`. They use the same `pulsar.json` manifest, lifecycle hooks, and capability model as any third-party extension.
- **Libsodium-only core crypto (ADR-0006).** The framework's own crypto uses libsodium exclusively. OAuth2/OIDC requires RSA/ECDSA signing (RS256, ES256) for JWT access tokens and ID tokens - these algorithms are outside libsodium's scope and must come from adapter libraries.
- **No framework-level composer dependencies.** Pulsar's root `composer.json` has zero non-dev external dependencies. Extension adapters declare their own library requirements in per-extension `composer.json` files.
- **Trust tier model (ADR-0023).** OAuth2/WebAuthn extensions are first-party `Core` tier, granting `CryptoKeyAccess` for signing key management via KeyRing.

### Forces

1. **Correctness over control.** Mature, audited libraries with thousands of deployments are more trustworthy than greenfield implementations for protocol compliance.
2. **Upgradeability.** Libraries are behind Pulsar port interfaces. Swapping a library (or a library major version) is an adapter-only change - no consumer-facing API changes.
3. **Supply chain risk.** Each external dependency is a potential attack vector. Minimizing the dependency tree and selecting well-maintained packages reduces this risk.
4. **PHP 8.5 compatibility.** All selected libraries must run on PHP 8.5 without modification.

## Decision Drivers

1. Security audit history and community trust
2. PHP 8.5 compatibility (explicit or via `>=8.2` constraint)
3. MIT/BSD license compatibility with Pulsar's Apache-2.0 license
4. Protocol coverage (RFC completeness)
5. Maintenance velocity and responsiveness to CVEs
6. Minimal transitive dependency tree

## Decision

Adopt a **Contract-First + Audited Library Adapter** architecture. Pulsar defines port interfaces (the public API) in each extension's `Contracts/` directory. Internal adapter classes wrap third-party library calls behind these ports. Consumers depend only on Pulsar interfaces - never on library types directly.

### Selected Libraries

#### 1. OAuth2/OIDC Server: `league/oauth2-server` ^9.3

| Criterion            | Assessment                                                                                                                                                      |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Version**          | 9.3.0 (released 2025-11-25)                                                                                                                                     |
| **PHP constraint**   | `~8.1.0 \| ~8.2.0 \| ~8.3.0 \| ~8.4.0 \| ~8.5.0` - PHP 8.5 explicitly supported                                                                                 |
| **License**          | MIT                                                                                                                                                             |
| **Security audit**   | Mozilla Secure Open Source programme audit; findings fixed in 5.1.4/6.0.0. CVE-2023-37260 (key exposure in error messages) fixed in 8.5.3. No open CVEs in v9.x |
| **Grant types**      | Authorization Code + PKCE (mandatory for public clients), Client Credentials, Refresh Token. Password grant deprecated (aligns with OAuth 2.1 draft)            |
| **Token management** | Built-in revocation via `RevokeTokenHandler` (RFC 7009). Introspection requires adapter-level implementation against repository interfaces                      |
| **Key dependencies** | `lcobucci/jwt` (JWT encoding/signing), `defuse/php-encryption` (optional encryption), `league/event`                                                            |
| **Downloads**        | 431M+ on Packagist - the most deployed PHP OAuth2 server implementation                                                                                         |
| **Maintenance**      | Active. 9.3.0 shipped Nov 2025. Regular releases addressing security and compatibility                                                                          |

**Rationale.** `league/oauth2-server` is the de facto standard PHP OAuth2 server library. Its repository-based architecture (implementer provides `ClientRepositoryInterface`, `AccessTokenRepositoryInterface`, `ScopeRepositoryInterface`, etc.) maps naturally to Pulsar's port/adapter model. Pulsar adapters will implement League's repository interfaces by delegating to Pulsar's own storage contracts, keeping the database layer fully decoupled.

**What Pulsar wraps.** The extension exposes Pulsar port interfaces (`AuthorizationServerInterface`, `TokenIssuerInterface`, `TokenRevokerInterface`, `TokenIntrospectorInterface`). Internal adapters instantiate League's `AuthorizationServer`, configure grants, and translate between Pulsar's request/response types and League's PSR-7-based API. Consumers never import `League\OAuth2\Server\*` types.

#### 2. WebAuthn/Passkeys: `web-auth/webauthn-lib` ^5.2

| Criterion               | Assessment                                                                                                                        |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| **Version**             | 5.2.2 (released 2025-03-16)                                                                                                       |
| **PHP constraint**      | `>=8.2` - PHP 8.5 compatible via open upper bound                                                                                 |
| **License**             | MIT                                                                                                                               |
| **FIDO conformance**    | FIDO Alliance conformance-tested. Supports registration and authentication ceremonies, passkeys, resident keys, user verification |
| **Attestation formats** | `none`, `packed`, `fido-u2f`, `android-key`, `android-safetynet`, `apple`, `tpm`                                                  |
| **Key dependencies**    | `symfony/uid`, CBOR libraries. No framework coupling (framework-agnostic core, Symfony bundle is a separate optional package)     |
| **Downloads**           | 18M+ on Packagist                                                                                                                 |
| **Maintenance**         | Active. Spomky-Labs maintains the full FIDO2/WebAuthn ecosystem for PHP                                                           |

**Rationale.** `web-auth/webauthn-lib` is the most comprehensive and conformance-tested PHP WebAuthn library. It covers the full FIDO2 specification including advanced attestation formats needed for regulated environments. The library-only package (`webauthn-lib`) avoids Symfony bundle coupling, fitting Pulsar's framework-agnostic extension model.

**What Pulsar wraps.** The extension exposes `WebAuthnRegistrarInterface` (registration ceremony), `WebAuthnAuthenticatorInterface` (authentication ceremony), and `CredentialRepositoryInterface` (credential storage). Internal adapters configure `web-auth/webauthn-lib`'s `PublicKeyCredentialCreationOptions` and `PublicKeyCredentialRequestOptions` builders, translate attestation/assertion results into Pulsar domain types, and delegate credential persistence to the application-provided repository.

#### 3. JOSE (JWT/JWK/JWA): `web-token/jwt-framework` ^4.0

| Criterion            | Assessment                                                                                      |
| -------------------- | ----------------------------------------------------------------------------------------------- |
| **Version**          | 4.0.x (latest stable)                                                                           |
| **PHP constraint**   | `>=8.2` - PHP 8.5 compatible via open upper bound                                               |
| **License**          | MIT                                                                                             |
| **Standards**        | Full JOSE suite: JWS (RFC 7515), JWE (RFC 7516), JWK (RFC 7517), JWA (RFC 7518), JWT (RFC 7519) |
| **Algorithms**       | RS256, RS384, RS512, ES256, ES384, ES512, EdDSA, PS256, PS384, PS512, and symmetric algorithms  |
| **JWK Sets**         | Full JWK Set support with key rotation, thumbprints (RFC 7638), key ID resolution               |
| **Key dependencies** | Minimal. Uses `ext-openssl`, `ext-mbstring`                                                     |
| **Downloads**        | 18M+ on Packagist                                                                               |
| **Maintenance**      | Active. Same maintainer (Spomky-Labs) as the WebAuthn library                                   |

**Rationale.** While `league/oauth2-server` ships with `lcobucci/jwt` for basic JWT operations, Pulsar's OIDC provider layer requires full JOSE capabilities: JWK Set publishing (`.well-known/jwks.json`), key rotation with `kid` matching, ID token signing with multiple algorithm support, and eventually JWE for encrypted tokens. `web-token/jwt-framework` provides the complete JOSE stack needed for a compliant OIDC provider.

`firebase/php-jwt` was considered but rejected - it covers basic JWT encode/decode but lacks JWK Set management, key rotation, JWE support, and the broader JOSE specification coverage required for a full OIDC provider implementation.

**What Pulsar wraps.** The OAuth2/OIDC extension uses `web-token/jwt-framework` internally for: signing access tokens and ID tokens (JWS), publishing JWK Sets for token verification, resolving signing keys by `kid` during key rotation, and (future) encrypting tokens (JWE) for confidential clients. These operations are behind Pulsar's `JwtServiceInterface` and `JwkSetProviderInterface` ports.

### Rejected Alternative: `firebase/php-jwt`

| Criterion              | `firebase/php-jwt`  | `web-token/jwt-framework` |
| ---------------------- | ------------------- | ------------------------- |
| JWK Set management     | No                  | Yes                       |
| Key rotation / `kid`   | Manual              | Built-in                  |
| JWE (encrypted tokens) | No                  | Yes                       |
| Algorithm breadth      | RS256, ES256, EdDSA | Full JWA suite            |
| RFC 7638 thumbprints   | No                  | Yes                       |

`firebase/php-jwt` is excellent for simple JWT verification (as in the existing `social-sso` extension's client-side ID token verification). For server-side token issuance with OIDC compliance, it lacks the required JWK Set and key management features.

## Integration Architecture

### KeyRing Integration (Finding B)

All signing keys for OAuth2 tokens and OIDC ID tokens are managed through Pulsar's `KeyRingInterface` (`src/Security/Crypto/KeyRingInterface.php`). The adapter libraries never generate, store, or manage key material independently.

**Flow:**

1. At boot, the extension's service provider resolves `KeyRingInterface` from the container.
2. A dedicated `OAuth2KeyRing` adapter (implementing both `KeyRingInterface` and League's `CryptKeyInterface`) derives purpose-specific asymmetric keys from the master key derivation chain. Sub-key ID `3` is reserved for OAuth2 signing, with KDF context `pulsar__oauth2_s` (8 bytes).
3. For RSA/ECDSA keys (required by JWT RFCs), the extension loads PEM key files whose paths are configured in the extension's config DTO. The `KeyRingInterface` indexes these by their JWK `kid` (computed as RFC 7638 thumbprint).
4. Key rotation uses the existing `KeyRingInterface.all()` to expose both current and previous keys in the published JWK Set, while only the current key signs new tokens.

**Why asymmetric keys live outside MasterKey KDF:** ADR-0006 specifies libsodium-only symmetric primitives for the MasterKey. RSA/ECDSA signing keys are fundamentally asymmetric and cannot be derived from the sodium KDF. The extension manages asymmetric key files separately, registered into the KeyRing by `kid`. The MasterKey still derives the symmetric key used for opaque token encryption (e.g., refresh token payloads).

### Audit Trail Integration (Finding D)

Every security-relevant OAuth2/WebAuthn event is logged via `AuditLoggerInterface` (`src/Audit/AuditLoggerInterface.php`), producing HMAC-chained, tamper-evident entries per ADR-0008.

| Event                    | AuditEvent       | Metadata                                                   |
| ------------------------ | ---------------- | ---------------------------------------------------------- |
| Token issued             | `authentication` | `grant_type`, `client_id`, `scopes`, `token_id`            |
| Token refreshed          | `authentication` | `client_id`, `old_token_id`, `new_token_id`                |
| Token revoked            | `authentication` | `client_id`, `token_id`, `revocation_source`               |
| Token introspection      | `data_access`    | `client_id`, `token_id`, `active`                          |
| Authorization denied     | `authorization`  | `client_id`, `reason`, `redirect_uri`                      |
| WebAuthn registration    | `authentication` | `credential_id`, `attestation_format`, `user_verification` |
| WebAuthn authentication  | `authentication` | `credential_id`, `sign_count`, `user_verification`         |
| WebAuthn ceremony failed | `authentication` | `credential_id`, `failure_reason`                          |

All audit entries include `correlation_id` and `causation_id` from `RequestContext` when available (auto-enrichment by `AuditLogger`).

**Replay safety:** Token issuance events include the `jti` (JWT ID) claim, which is both unique and auditable. Refresh token rotation invalidates the old token atomically - if the old token is replayed, the audit trail shows the revocation event and the token repository rejects it. WebAuthn authentication includes the `signCount` from the authenticator, which is monotonically increasing and detects cloned credentials.

### Session Integration

OAuth2 authorization code flow requires server-side state during the user consent phase:

1. **Authorization request** stores `state`, `code_challenge`, `code_challenge_method`, `redirect_uri`, `requested_scopes`, and `client_id` in the session via `SessionInterface`.
2. **Consent confirmation** reads session state, generates the authorization code, stores the code-to-grant mapping, and clears session state.
3. **Token exchange** is sessionless (direct POST to token endpoint).

WebAuthn ceremonies use the session for challenge storage:

1. **Registration ceremony** stores `PublicKeyCredentialCreationOptions` (serialized) in the session.
2. **Authentication ceremony** stores `PublicKeyCredentialRequestOptions` (serialized) in the session.
3. **Ceremony completion** reads, validates, and clears the challenge from the session.

Session data is encrypted at rest when `SessionEncryption` is configured (default for production per Plan 08).

### Guard Integration

Two new guards integrate with `AuthManagerInterface`:

- **`OAuth2Guard`** - Implements `GuardInterface`. Extracts Bearer tokens from the `Authorization` header (like the existing `TokenGuard`), validates the JWT signature and claims using the JOSE library, and resolves the token to an `IdentityInterface`. Supports both JWT access tokens (self-contained validation) and opaque tokens (repository lookup + introspection).
- **`WebAuthnGuard`** - Implements `GuardInterface`. For session-based WebAuthn flows, delegates to `SessionGuard` after successful WebAuthn ceremony. For token-based API access after WebAuthn authentication, the WebAuthn ceremony issues an OAuth2 token that is then validated by `OAuth2Guard`.

Guard priority: `OAuth2Guard` runs before `SessionGuard` (Bearer token takes precedence over session cookie in API contexts).

## Extension Structure

```
extensions/oauth2-server/
  pulsar.json
  composer.json              # requires league/oauth2-server ^9.3, web-token/jwt-framework ^4.0
  src/
    Contracts/               # Public port interfaces (#[Api])
      AuthorizationServerInterface.php
      TokenIssuerInterface.php
      TokenRevokerInterface.php
      TokenIntrospectorInterface.php
      JwtServiceInterface.php
      JwkSetProviderInterface.php
      ClientRepositoryInterface.php
      ScopeRepositoryInterface.php
      AccessTokenRepositoryInterface.php
      RefreshTokenRepositoryInterface.php
      AuthorizationCodeRepositoryInterface.php
    Domain/                  # Public value objects (#[Api])
      OAuth2Client.php
      OAuth2Scope.php
      AccessToken.php
      RefreshToken.php
      AuthorizationCode.php
      TokenIntrospectionResult.php
    Config/
      OAuth2Config.php       # Readonly DTO with fromArray() factory
    Exception/
      OAuth2Exception.php    # Static factory methods
    Features/
      Authorize/             # Authorization code request + consent
      Token/                 # Token issuance
      Revoke/                # Token revocation
      Introspect/            # Token introspection
      Discovery/             # .well-known/openid-configuration
      Jwks/                  # .well-known/jwks.json
      UserInfo/              # OIDC UserInfo endpoint
    Internal/
      Adapter/               # Library adapter implementations (#[Internal])
        LeagueAuthorizationServer.php
        LeagueGrantFactory.php
        JwtFrameworkSigner.php
        JwtFrameworkJwkSetProvider.php
      Repository/            # League repository adapters (#[Internal])
        LeagueClientRepository.php
        LeagueScopeRepository.php
        LeagueAccessTokenRepository.php
        LeagueRefreshTokenRepository.php
        LeagueAuthCodeRepository.php
    Guard/
      OAuth2Guard.php
    OAuth2Extension.php
    OAuth2ServiceProvider.php

extensions/webauthn/
  pulsar.json
  composer.json              # requires web-auth/webauthn-lib ^5.2
  src/
    Contracts/               # Public port interfaces (#[Api])
      WebAuthnRegistrarInterface.php
      WebAuthnAuthenticatorInterface.php
      CredentialRepositoryInterface.php
      CeremonyOptionsFactoryInterface.php
    Domain/                  # Public value objects (#[Api])
      PublicKeyCredential.php
      RegistrationResult.php
      AuthenticationResult.php
      CeremonyChallenge.php
    Config/
      WebAuthnConfig.php     # Readonly DTO with fromArray() factory
    Exception/
      WebAuthnException.php  # Static factory methods
    Features/
      Register/              # Registration ceremony
      Authenticate/          # Authentication ceremony
      Manage/                # Credential management (list, revoke)
    Internal/
      Adapter/               # Library adapter implementations (#[Internal])
        SpomkyRegistrar.php
        SpomkyAuthenticator.php
        SpomkyCeremonyOptionsFactory.php
    Guard/
      WebAuthnGuard.php
    WebAuthnExtension.php
    WebAuthnServiceProvider.php
```

## Consequences

### Positive

- **Protocol compliance via proven libraries.** `league/oauth2-server` has 431M+ installs and a Mozilla security audit. `web-auth/webauthn-lib` is FIDO conformance-tested. These libraries carry far more real-world validation than any greenfield implementation could achieve.
- **Adapter isolation.** Pulsar consumers depend on `Contracts/` interfaces, not library types. Library major version upgrades (e.g., League v9 to v10) require adapter changes only - zero impact on application code.
- **No core dependency bloat.** Libraries are extension-level dependencies in per-extension `composer.json`. Applications that do not enable OAuth2/WebAuthn pull zero additional packages.
- **KeyRing-centralized key management.** All signing keys flow through `KeyRingInterface`, enabling unified rotation, audit, and access control via the trust tier model.
- **Full audit trail.** Every token lifecycle event and WebAuthn ceremony is HMAC-chain logged, satisfying compliance requirements for authentication audit trails.

### Negative

- **Three external dependencies per extension.** The OAuth2 extension pulls `league/oauth2-server` (with transitive `lcobucci/jwt`, `league/event`, `defuse/php-encryption`), plus `web-token/jwt-framework`. The WebAuthn extension pulls `web-auth/webauthn-lib` (with transitive CBOR, `symfony/uid`). Each is a supply chain surface.
- **Adapter maintenance burden.** Library API changes (especially in major versions) require adapter updates. The port/adapter layer adds indirection that must be maintained.
- **RSA/ECDSA key management.** Asymmetric signing keys cannot be derived from the libsodium MasterKey, requiring separate key file management and configuration. This adds operational complexity compared to the symmetric-only model.

### Neutral

- **Dual JWT libraries.** The OAuth2 extension will have both `lcobucci/jwt` (transitive via League) and `web-token/jwt-framework` (direct dependency for OIDC). This is acceptable - `lcobucci/jwt` is used internally by League for its token encoding, while `web-token/jwt-framework` handles the OIDC-specific JOSE operations (JWK Sets, key rotation, ID token signing). They operate at different layers and do not conflict.
- **Attestation format policy.** For most deployments, `none` attestation suffices. Regulated environments requiring hardware attestation (`packed`, `tpm`) can configure the WebAuthn extension's attestation policy via `WebAuthnConfig`. The library supports all formats out of the box.

## Security Considerations

### Supply Chain

- **Pinned versions.** Extension `composer.json` files use caret constraints (`^9.3`, `^5.2`, `^4.0`) to receive patch-level security fixes while preventing unreviewed major version changes.
- **Composer audit.** CI pipeline runs `composer audit` to detect known vulnerabilities in dependencies.
- **Dependency review.** All transitive dependencies have been reviewed: `lcobucci/jwt` (MIT, 76M+ installs), `league/event` (MIT, 72M+ installs), `defuse/php-encryption` (MIT, security-focused), `symfony/uid` (MIT, Symfony ecosystem).
- **Update policy.** Security advisories for adapter libraries trigger a patch release within 48 hours. Major version upgrades are evaluated within 30 days of release and adopted if the adapter layer can absorb the changes without breaking Pulsar's public API.

### Cryptographic Boundaries

- **Libsodium boundary preserved.** Core framework crypto (encryption, HMAC, KDF) remains libsodium-only per ADR-0006. The OpenSSL/RSA/ECDSA usage is confined entirely within extension adapter code, behind port interfaces.
- **Key material isolation.** Private signing keys are loaded into memory only during token signing operations. The `OAuth2Config` DTO stores key file paths, never raw key material. `KeyRingInterface` provides access-controlled key resolution.
- **No algorithm downgrade.** The adapter enforces a minimum algorithm strength: RS256 or ES256 for access tokens, ES256 preferred for new deployments. `none` algorithm is rejected at the adapter layer (defense-in-depth beyond library-level checks).

### WebAuthn-Specific

- **Challenge entropy.** Registration and authentication challenges use `random_bytes(32)` (256-bit entropy), stored in the encrypted session.
- **Sign count validation.** The adapter verifies that the authenticator's `signCount` is strictly greater than the stored value, detecting cloned credentials.
- **Origin validation.** The relying party ID and origin are validated against `WebAuthnConfig`, preventing phishing relay attacks.

## Performance Impact

Minimal impact on hot paths:

- **Token validation** (OAuth2Guard): JWT signature verification is a single OpenSSL operation (~0.1ms for RS256, ~0.05ms for ES256). JWK Set is cached in memory after first load.
- **Token issuance**: One signing operation per token request. Not on the hot path (token endpoints are low-frequency relative to API requests).
- **WebAuthn ceremonies**: CBOR decoding and attestation validation occur only during registration/authentication - not per-request.

No performance budget violations expected. Token validation is comparable to the existing `TokenGuard` + `TokenResolverInterface` flow.

## Migration / Rollback Plan

**Adoption:**

1. Install the `oauth2-server` and/or `webauthn` extensions (add to `extensions/` directory).
2. Run `composer install` within each extension directory.
3. Register extensions in the application's extension configuration.
4. Implement the application-specific repository interfaces (`ClientRepositoryInterface`, `CredentialRepositoryInterface`, etc.) and bind them in the container.
5. Configure `OAuth2Config` and `WebAuthnConfig` DTOs with signing key paths, client credentials, and relying party settings.
6. Register `OAuth2Guard` and/or `WebAuthnGuard` with `AuthManagerInterface`.

**Rollback:**

1. Remove the extension directories and their composer dependencies.
2. Unregister the extensions and guards.
3. No schema migrations or data changes required at the framework level (repository implementations are application-provided).

## Links

- ADR-0002: Modular Monolith with Vertical Slices and Ports/Adapters
- ADR-0004: Extension-First Architecture with Manifest-Driven Lifecycle
- ADR-0006: Libsodium-Only Cryptography with Master Key Derivation
- ADR-0008: HMAC-Chained Tamper-Evident Audit Logging
- ADR-0009: Attribute-Based Public API Surface
- ADR-0023: Extension Trust Tiers & Capability Enforcement
- [league/oauth2-server on Packagist](https://packagist.org/packages/league/oauth2-server)
- [web-auth/webauthn-lib on Packagist](https://packagist.org/packages/web-auth/webauthn-lib)
- [web-token/jwt-framework on Packagist](https://packagist.org/packages/web-token/jwt-framework)
- RFC 6749: The OAuth 2.0 Authorization Framework
- RFC 7636: Proof Key for Code Exchange (PKCE)
- RFC 7662: OAuth 2.0 Token Introspection
- RFC 7009: OAuth 2.0 Token Revocation
- OpenID Connect Core 1.0
- WebAuthn Level 2 (W3C Recommendation)
