# OAuth2/OIDC + WebAuthn Compliance Gap Analysis

**Reviewer:** Legal & Compliance Specialist
**Date:** 2026-02-16
**Scope:** Extensions `pulsar/oauth2` and `pulsar/webauthn` (rc.11 contract surface)
**Status:** Pre-release review (implementations in progress)

> **Framing note (Finding C):** This document evaluates whether Pulsar's OAuth2/OIDC and WebAuthn extensions provide controls that _support_ regulatory compliance. Pulsar is a framework, not an end-to-end regulated application. Compliance is ultimately the responsibility of the deploying organization. Language throughout this document uses "Pulsar provides controls that support..." rather than "Pulsar ensures compliance with..."

---

## 1. PSD2 Strong Customer Authentication (SCA)

PSD2 (Directive 2015/2366/EU), supplemented by the EBA Regulatory Technical Standards on SCA (Commission Delegated Regulation 2018/389), requires payment service providers to apply strong customer authentication when the payer initiates an electronic payment transaction or carries out actions through a remote channel that may imply a risk of fraud.

### 1.1 Multi-Factor Requirement (RTS Art. 4)

**Requirement:** SCA demands authentication using two or more elements from: (a) knowledge (password, PIN), (b) possession (device, token), (c) inherence (biometric).

**Current status: Supported with integration guidance**

Pulsar provides controls that support multi-factor authentication through the combination of its OAuth2 and WebAuthn extensions:

- **WebAuthn as second factor:** The `WebAuthnServerInterface` supports both registration and authentication ceremonies. The `AuthenticationResult` object includes a `userVerified` flag (line 19 of `AuthenticationResult.php`), which indicates whether the authenticator performed user verification (e.g., biometric or PIN). This maps to the _inherence_ or _knowledge_ factor depending on the authenticator type.
- **Possession factor:** WebAuthn authenticators inherently provide the _possession_ factor through the cryptographic credential bound to the physical device.
- **Knowledge factor:** The OAuth2 authorization code flow supports a first-factor password/PIN step before WebAuthn assertion, delegating first-factor verification to the application's identity provider.
- **Factor independence (RTS Art. 9):** The WebAuthn credential is bound to a specific authenticator (per `CredentialSource.credentialId`), and the OAuth2 session is bound separately via `RefreshToken.sessionId`. Compromise of one factor does not compromise the other.

**Gap: No explicit `AuthenticationContext` or `AuthenticationLevel` enum**

The design does not include a first-class representation of the authentication factors used during a session. There is no `AuthenticationContext` value object that records which factor types were satisfied (knowledge, possession, inherence) or an `AuthenticationAssuranceLevel` (AAL) enum. This means:

- Downstream authorization decisions cannot programmatically query "was this session authenticated with 2+ factors?" without custom integration logic.
- There is no contract-level hook for enforcing "step-up authentication" when a higher assurance level is needed mid-session.

**Recommendation:**

1. Add an `AuthenticationAssuranceLevel` enum (AAL1, AAL2, AAL3 per NIST SP 800-63B) to the WebAuthn extension's public API.
2. Add an `authenticationContext` or `acrValues` field to the `AuthenticationResult` and/or the OAuth2 token claims, so that relying parties can make factor-aware authorization decisions.
3. Document the integration pattern for combining OAuth2 first-factor with WebAuthn second-factor in a single authorization flow.

### 1.2 Dynamic Linking for Payment Transactions (RTS Art. 5)

**Requirement:** For remote electronic payment transactions, SCA must include elements that dynamically link the authentication to the specific transaction amount and payee.

**Current status: Gap**

The current contract surface does not provide a mechanism to bind authentication to transaction-specific data. Specifically:

- `AuthorizationServerInterface::handleAuthorizationRequest()` accepts a generic `ServerRequestInterface` but has no structured hook for injecting transaction metadata (amount, payee, IBAN) into the authorization flow.
- `WebAuthnServerInterface::generateAuthenticationOptions()` does not accept a `transactionContext` parameter that could embed transaction details in the WebAuthn challenge.
- The `AuthenticationOptions` and `AuthenticationResult` value objects do not carry transaction-binding metadata.
- ID tokens (via `IdTokenBuilder`) do not include transaction-specific claims.

**Recommendation:**

1. Add an optional `transactionContext` parameter (or a `DynamicLinkingContext` DTO) to `WebAuthnServerInterface::generateAuthenticationOptions()` that embeds amount and payee in the challenge or client data.
2. Add a `txn` claim to the ID token builder for transaction-bound authentication (see OpenID Financial-grade API, FAPI 2.0).
3. Document that for PSD2 dynamic linking, deployers must ensure the signed challenge or assertion contains the transaction data, and that this data is displayed to the user before confirmation.

### 1.3 SCA Exemptions (RTS Art. 10-18)

**Requirement:** PSD2 allows exemptions from SCA for low-value transactions (< EUR 30), contactless payments, recurring transactions to trusted beneficiaries, secure corporate payments, and transaction risk analysis (TRA) exemptions.

**Current status: Gap (by design)**

The OAuth2 and WebAuthn contracts do not include exemption logic. This is architecturally appropriate - exemption decisions are business-logic concerns that belong in the deploying application, not in the authentication framework.

**Recommendation:**

1. Document that SCA exemptions are the responsibility of the deploying payment service provider.
2. Ensure that the authorization flow supports conditional SCA - the `handleAuthorizationRequest` method should allow the application layer to bypass second-factor authentication when an exemption applies. Currently the flow is opaque (single `ServerRequestInterface` in, `ResponseInterface` out), so the application needs a hook to signal "SCA not required for this transaction."
3. Consider adding an `ScaExemptionReason` enum or a `bypass_sca` context flag to the authorization request processing, so that exemption decisions are audit-logged alongside the authentication event.

---

## 2. eIDAS Alignment

eIDAS (Regulation 910/2014/EU) and eIDAS 2.0 (proposed revision) establish a framework for electronic identification and trust services across EU member states.

### 2.1 Identity Assurance Levels (eIDAS Art. 8)

**Requirement:** eIDAS defines three levels of assurance for electronic identification: low, substantial, and high (Commission Implementing Regulation 2015/1502).

**Current status: Partially supported**

- The `AttestationTrustLevel` enum (`None`, `Self`, `Basic`, `AttestationCa`) in the WebAuthn extension provides a trust classification for authenticator attestation, which maps conceptually to assurance levels.
- The `AuthenticatorType` enum distinguishes `Platform` (Touch ID, Windows Hello) from `CrossPlatform` (security keys), which is relevant to assurance level determination.
- The `AuthenticationResult.userVerified` flag provides signal about user verification method.

**Gap: No first-class `IdentityAssuranceLevel` representation**

The attestation trust level is not the same as the eIDAS identity assurance level. eIDAS assurance depends on the _entire_ identity proofing and authentication chain, not just the authenticator's attestation. There is no mapping or enum for eIDAS assurance levels.

**Recommendation:**

1. Consider adding an `IdentityAssuranceLevel` enum (`Low`, `Substantial`, `High`) to the contract surface, or at minimum document the mapping between `AttestationTrustLevel` + `userVerified` + authenticator metadata and eIDAS levels.
2. Document that eIDAS identity assurance is a deployment-time configuration concern - the framework provides the building blocks (attestation verification, user verification signals), but the deploying organization must define the assurance level mapping based on their identity proofing process.

### 2.2 Cross-Border Interoperability

**Requirement:** eIDAS mandates mutual recognition of electronic identification means notified by member states.

**Current status: Not in scope (appropriate)**

Cross-border eID interoperability (via the eIDAS node infrastructure) is an integration concern for national identity schemes, not for an application framework. The OIDC-based contract surface is compatible with eIDAS-compliant identity providers (many EU eID schemes use OIDC).

**Recommendation:**

1. Document that Pulsar's OIDC provider is interoperable with eIDAS-notified identity schemes when deployed behind an eIDAS node or national identity gateway.
2. The `UserClaimsProviderInterface.getSubjectIdentifier()` supports pairwise identifiers per OIDC Core 8.1, which is relevant to privacy requirements in cross-border eID flows.

---

## 3. GDPR Compliance

GDPR (Regulation 2016/679/EU) imposes obligations on data controllers and processors regarding personal data processing, including consent management, data minimization, and data subject rights.

### 3.1 Granular Per-Scope Consent (GDPR Art. 7, Recital 32)

**Requirement:** Consent must be specific, informed, freely given, and distinguishable from other matters. Blanket consent is not valid.

**Current status: Compliant**

Pulsar provides controls that support granular consent through the `ConsentRepositoryInterface`:

- `grantConsent(subjectId, clientId, scopes)` records consent per-scope (line 32 of `ConsentRepositoryInterface.php`).
- `hasConsent(subjectId, clientId, scopes)` checks consent at scope granularity (line 25).
- Scopes map to specific data categories via `UserClaimsProviderInterface` (OIDC standard scopes: `profile`, `email`, `address`, `phone` each map to distinct claim sets).

**Strength:** The scope-to-claim mapping is well-defined in `UserClaimsProviderInterface` (lines 15-21), ensuring that consent for `email` scope only exposes `email` and `email_verified` claims, not broader profile data.

### 3.2 Consent Revocation (GDPR Art. 7(3))

**Requirement:** The data subject shall have the right to withdraw consent at any time. Withdrawal must be as easy as giving consent.

**Current status: Compliant**

- `ConsentRepositoryInterface::revokeConsent(subjectId, clientId)` revokes all consent for a specific client (line 37).
- `listConsents(subjectId)` allows users to view all active consents (line 44).
- Token revocation is supported at multiple levels: individual token (`revoke(tokenId)`), all tokens by subject (`revokeBySubject(subjectId)`), and entire token families (`revokeFamily(familyId)`).

**Minor gap: No per-scope revocation**

`revokeConsent()` revokes all scopes for a client. There is no `revokeScope(subjectId, clientId, scope)` method to revoke individual scopes while maintaining others. GDPR requires that withdrawal be at least as granular as the original consent.

**Recommendation:**

1. Add a `revokeScope(string $subjectId, string $clientId, string $scope): void` method to `ConsentRepositoryInterface` that removes a single scope from an existing consent record without affecting other consented scopes.

### 3.3 Data Minimization (GDPR Art. 5(1)(c))

**Requirement:** Personal data must be adequate, relevant, and limited to what is necessary.

**Current status: Compliant**

- `UserClaimsProviderInterface::getClaims(subjectId, scopes)` returns claims filtered by granted scopes (line 33). Only claims mapped to consented scopes are included.
- OIDC standard scope-to-claim mappings enforce data minimization by design - requesting `openid` alone yields only the `sub` claim; additional claims require explicit scope grants.
- Pairwise subject identifiers (via `getSubjectIdentifier(userId, clientId)`) prevent cross-client user tracking, which supports data minimization across relying parties.

### 3.4 Consent Timestamping and Auditability (GDPR Art. 7(1))

**Requirement:** The controller must be able to demonstrate that the data subject has consented.

**Current status: Partially compliant**

- `ConsentRecord` includes `grantedAt` (a `DateTimeImmutable` timestamp, line 26 of `ConsentRecord.php`).
- ADR-0025 states that consent grant/revoke events emit `Authorization` audit entries (audit table in Section "Replay-Safety and Audit").

**Gap: No IP address, user agent, or consent version tracking**

The `ConsentRecord` does not capture the context in which consent was given (IP address, user agent, consent text version). For GDPR Art. 7(1) demonstrability, a record of _what_ the user consented to (the specific text/scope descriptions shown) and _how_ (the requesting context) strengthens the evidential chain.

**Recommendation:**

1. Add optional `ipAddress`, `userAgent`, and `consentVersion` fields to `ConsentRecord` so that deployers can capture consent context.
2. Alternatively, document that deployers should log consent context in their own audit systems alongside the Pulsar consent record.

### 3.5 Right to Erasure (GDPR Art. 17)

**Requirement:** The data subject has the right to obtain erasure of personal data ("right to be forgotten").

**Current status: Supported**

Pulsar provides controls that support data erasure at multiple levels:

- **Tokens:** `AccessTokenRepositoryInterface::revokeBySubject(subjectId)` and `RefreshTokenRepositoryInterface::revokeBySubject(subjectId)` revoke all tokens for a user.
- **Consent:** `ConsentRepositoryInterface::revokeConsent(subjectId, clientId)` revokes consent per-client, and `listConsents(subjectId)` enables iterating over all clients to revoke all consents.
- **WebAuthn credentials:** `CredentialRepositoryInterface::removeByUserId(userId)` removes all credentials for a user.
- **Authenticator records:** `AuthenticatorRepositoryInterface` does not have a `removeByUserId()` method.

**Gap: No bulk erasure orchestrator**

While individual repositories support subject-level deletion, there is no coordinated `eraseSubject(subjectId)` method that atomically purges all user data across tokens, consents, credentials, and authenticator records. Deployers must manually call each repository.

**Gap: AuthenticatorRepositoryInterface missing `removeByUserId()`**

The `AuthenticatorRepositoryInterface` has a `revoke(credentialId)` method (soft delete) but no `removeByUserId()` for hard deletion. The `CredentialRepositoryInterface` has `removeByUserId()`, but the `AuthenticatorRepositoryInterface` does not, creating an incomplete erasure path.

**Recommendation:**

1. Add `removeByUserId(string $userId): void` to `AuthenticatorRepositoryInterface` for complete credential erasure.
2. Document the recommended erasure sequence for deployers: revoke all tokens -> revoke all consents -> remove all WebAuthn credentials -> remove all authenticator records -> purge user from claims provider.
3. Consider providing a utility class (non-contract) that orchestrates the full erasure sequence.

---

## 4. Token Storage Security

### 4.1 Token Hashing (OWASP, PCI DSS Req. 8.3.2)

**Requirement:** Sensitive authentication data must not be stored in cleartext.

**Current status: Compliant**

Pulsar provides controls that support secure token storage:

- **Authorization codes:** Stored hashed per `AuthorizationCodeRepositoryInterface` docblock ("The code value is hashed before storage. Never stored in plaintext.").
- **Refresh tokens:** Stored hashed per `RefreshTokenRepositoryInterface` docblock ("The token value is hashed before storage.").
- **Access tokens (reference):** Stored hashed per `AccessTokenRepositoryInterface` docblock and confirmed in the `InMemoryAccessTokenRepository` implementation, which uses `hash('sha256', tokenValue)` (line 33, line 39).
- **Client secrets:** `OAuthClient.secretHash` (line 29 of `OAuthClient.php`) stores a hash, not plaintext.

**Finding: SHA-256 hashing for tokens**

The `InMemoryAccessTokenRepository` uses `hash('sha256', ...)` for token hashing. SHA-256 is a fast hash function. For tokens with sufficient entropy (which random-generated OAuth2 tokens should have), SHA-256 is acceptable. However, for client secrets that may have lower entropy, a slow hash (bcrypt/argon2) would be more appropriate.

**Recommendation:**

1. Ensure that production `ClientRepositoryInterface` implementations use `password_hash()` with Argon2id for client secret storage, not SHA-256.
2. Document that SHA-256 is acceptable for high-entropy tokens (access tokens, refresh tokens, authorization codes) but that client secrets must use a slow hash function.

### 4.2 Token Value Exposure Prevention

**Requirement:** Token values must not be logged or exposed in debug output.

**Current status: Compliant**

All domain objects containing sensitive values implement `__debugInfo()` with redaction:

- `AccessToken.__debugInfo()` redacts `tokenValue` as `[REDACTED]` (line 55).
- `RefreshToken.__debugInfo()` redacts `tokenValue` as `[REDACTED]` (line 64).
- `AuthorizationCode.__debugInfo()` redacts `codeValue`, `codeChallenge`, and `nonce` as `[REDACTED]` (lines 57-60).
- `OAuthClient.__debugInfo()` redacts `secretHash` as `[REDACTED]` (line 63).

**Strength:** The redaction pattern is consistent across all sensitive value objects.

### 4.3 Refresh Token Rotation and Replay Detection

**Current status: Compliant**

- `RefreshTokenRepositoryInterface` documents one-time use policy with rotation (line 6-7 docblock).
- `consume()` atomically marks tokens as consumed (line 32).
- `revokeFamily(familyId)` enables breach detection: reuse of a rotated-out token revokes the entire token family (lines 47-51).
- `RefreshToken` includes `familyId` for rotation tracking (line 29) and `consumed` flag (line 34).

This is a strong security control that supports PSD2 session integrity requirements.

---

## 5. Dynamic Client Registration

### 5.1 Default-Off Policy

**Requirement:** Dynamic client registration introduces supply chain risk and must be gated.

**Current status: Compliant**

- `ClientRepositoryInterface` docblock states: "Dynamic registration is disabled by default and requires explicit admin policy configuration" (lines 14-15).
- `OAuth2Exception::registrationDisabled()` provides a structured error for rejected registration attempts (line 65-68).

### 5.2 Audit Logging

**Requirement:** Client registration events must be audit-logged.

**Current status: Compliant (by architecture)**

ADR-0025 audit table lists "Client registration" as a `ConfigurationChange` audit event with "Admin-gated, audit-logged" protection.

### 5.3 Admin Gating

**Requirement:** Only authorized administrators should be able to register clients.

**Current status: Partially compliant (contract gap)**

The `ClientRepositoryInterface::register()` method does not include an authorization context parameter. The admin gating is described in the docblock but not enforced at the contract level. The method accepts any `OAuthClient` without requiring proof of admin authorization.

**Recommendation:**

1. Document that admin gating must be enforced at the middleware/controller layer before calling `register()`, not within the repository implementation.
2. Consider adding a `registeredBy` audit field to `OAuthClient` to track which administrator registered each client.

---

## 6. Compliance Framing Review (Finding C)

### 6.1 Documentation Language

**Requirement:** Framework documentation must not make compliance claims that could be interpreted as certification.

**Current status: Review needed**

The following items were checked:

- **ADR-0025:** Uses appropriate language ("Pulsar targets regulated domains"). Does not claim compliance.
- **Contract docblocks:** Use technical language without compliance assertions. Appropriate.
- **OidcDiscovery:** Serves standard OIDC metadata without compliance claims. Appropriate.

**Recommendation:**

1. All user-facing documentation for these extensions should use the framing: "Pulsar provides controls that support [regulation] compliance requirements." Never: "Pulsar ensures compliance with [regulation]" or "Pulsar is [regulation]-compliant."
2. Include a compliance disclaimer in the extension README or documentation index stating that regulatory compliance requires deployment-specific configuration, organizational policies, and potentially third-party audits.

---

## 7. Summary Assessment

### Compliance Scorecard

| Area                        | Status                     | Priority                     |
| --------------------------- | -------------------------- | ---------------------------- |
| PSD2 SCA multi-factor       | Supported                  | Low                          |
| PSD2 dynamic linking        | Gap                        | High (for payment use cases) |
| PSD2 SCA exemptions         | Gap (by design)            | Medium                       |
| eIDAS assurance levels      | Partially supported        | Medium                       |
| eIDAS cross-border          | Not in scope (appropriate) | N/A                          |
| GDPR granular consent       | Compliant                  | N/A                          |
| GDPR consent revocation     | Minor gap (per-scope)      | Low                          |
| GDPR data minimization      | Compliant                  | N/A                          |
| GDPR consent auditability   | Partially compliant        | Medium                       |
| GDPR right to erasure       | Supported (gaps)           | Medium                       |
| Token hashing               | Compliant                  | N/A                          |
| Token exposure prevention   | Compliant                  | N/A                          |
| Refresh token rotation      | Compliant                  | N/A                          |
| Dynamic client registration | Compliant                  | N/A                          |
| Compliance framing          | Appropriate                | Low                          |

### Recommended Contract Changes (Priority Order)

1. **High:** Add `DynamicLinkingContext` DTO and optional transaction binding to `WebAuthnServerInterface::generateAuthenticationOptions()` for PSD2 dynamic linking.
2. **Medium:** Add `AuthenticationAssuranceLevel` enum (AAL1/AAL2/AAL3) and `authenticationContext` to `AuthenticationResult`.
3. **Medium:** Add `removeByUserId(string $userId): void` to `AuthenticatorRepositoryInterface`.
4. **Medium:** Add optional consent context fields (`ipAddress`, `userAgent`, `consentVersion`) to `ConsentRecord`.
5. **Low:** Add `revokeScope(string $subjectId, string $clientId, string $scope): void` to `ConsentRepositoryInterface`.

### Recommended Documentation

1. Integration guide for PSD2 SCA: combining OAuth2 first-factor with WebAuthn second-factor.
2. Deployment guide for SCA exemption handling at the application layer.
3. Data erasure runbook: sequence for GDPR Art. 17 compliance.
4. Client secret hashing guidance: Argon2id for secrets, SHA-256 for high-entropy tokens.
5. Compliance disclaimer for extension documentation.

### Overall Assessment

Pulsar's OAuth2/OIDC and WebAuthn extensions provide a strong foundation for deployment in regulated domains. The contract-first architecture, token hashing, debug redaction, granular consent, refresh token rotation, and audit-logging design are well-aligned with PSD2, GDPR, and eIDAS requirements.

The primary gap is PSD2 dynamic linking for payment transactions, which requires transaction-specific data binding in the authentication ceremony. This is a high-priority item for banking use cases but does not affect non-payment deployments.

The remaining gaps are addressable through targeted contract additions (5 methods/fields) and documentation. None of the gaps represent architectural deficiencies - they are incremental improvements to an already-sound design.
