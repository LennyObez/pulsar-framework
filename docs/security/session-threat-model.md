# Session Management - Threat Model

## Asset

Session data: authentication state, user identity, CSRF tokens, flash messages, and application-specific session state. Session integrity is critical for maintaining authenticated user context across requests.

## Threat Actors

| Actor                    | Capability                                 | Motivation                             |
| ------------------------ | ------------------------------------------ | -------------------------------------- |
| Unauthenticated attacker | Network access, public endpoints           | Account takeover, data theft           |
| Authenticated attacker   | Valid session, limited permissions         | Privilege escalation, session abuse    |
| Network attacker (MITM)  | Traffic interception on untrusted networks | Session hijacking, credential theft    |
| Insider threat           | Application or infrastructure access       | Data exfiltration, unauthorized access |

## Attack Vectors and Mitigations

### 1. Session Hijacking (Network Sniffing)

**Attack**: Attacker intercepts session cookie over unencrypted connection.

**Mitigations**:

- `cookie_secure: true` - cookie only sent over HTTPS
- `cookie_samesite: Strict` - prevents cross-origin cookie leakage
- HSTS headers enforce HTTPS for all subsequent requests
- Session validators detect context changes (UA, IP)

**Residual Risk**: Low. Requires HTTPS misconfiguration or TLS downgrade.

### 2. Session Fixation

**Attack**: Attacker sets a known session ID on the victim's browser before login, then uses the same ID to access the authenticated session.

**Mitigations**:

- `regenerate_on_privilege_change: true` - session ID regenerated on login/privilege change (non-configurable for regulated presets)
- `use_strict_mode: true` (PHP setting) - rejects uninitialized session IDs
- `use_only_cookies: true` - prevents session ID in URL parameters
- Session ID generated with 256 bits of cryptographic randomness

**Residual Risk**: Negligible. All standard fixation vectors are blocked.

### 3. Session Replay (Cookie Replay)

**Attack**: Attacker captures a valid encrypted session cookie and replays it later.

**Mitigations**:

- Session lifetime enforced (default 7200s)
- Cookie handler includes `issued_at` timestamp with configurable replay window
- Session validators detect context changes between original and replayed request
- Server-side handlers (Redis/DB) can detect destroyed sessions

**Residual Risk**: Medium for cookie handler (stateless, no server-side nonce tracking). Low for Redis/DB handlers (server-side session state).

### 4. Cookie Theft via XSS

**Attack**: Attacker injects JavaScript to read session cookies.

**Mitigations**:

- `cookie_httponly: true` - prevents JavaScript access to session cookie
- Content Security Policy headers restrict script execution
- Session data encrypted at rest (even if cookie is somehow extracted)

**Residual Risk**: Low. Requires CSP bypass and HttpOnly bypass simultaneously.

### 5. Brute Force Session ID Guessing

**Attack**: Attacker attempts to guess valid session IDs.

**Mitigations**:

- 256-bit cryptographic random session IDs (2^256 keyspace)
- Rate limiting on session endpoints
- Session validation rejects unknown IDs

**Residual Risk**: Negligible. Computationally infeasible.

### 6. Session Data Tampering

**Attack**: Attacker modifies session data in transit or at rest.

**Mitigations**:

- AEAD encryption (XChaCha20-Poly1305) provides authenticated encryption - any modification is detected
- AAD binds ciphertext to session context (session ID, handler type, domain) - prevents payload transplant
- Key material zeroed from memory on destruction

**Residual Risk**: Negligible with encryption enabled. Without encryption, file-based sessions are vulnerable to local file access.

### 7. Concurrent Session Abuse

**Attack**: Attacker creates unlimited sessions to exhaust server resources or maintain persistent access from multiple locations.

**Mitigations**:

- Configurable concurrent session limit (default 3 per user)
- Redis: atomic Lua scripts for race-safe enforcement
- Database: transactional counting
- Session listing enables user/admin to view and revoke sessions

**Residual Risk**: Low for Redis/DB handlers. File/Cookie handlers cannot enforce limits.

### 8. Key Rotation Window Exploitation

**Attack**: Attacker exploits the window where both old and new keys are valid during key rotation.

**Mitigations**:

- Key rotation is time-bounded (remove previous key after all sessions expire)
- `key_id` in ciphertext header explicitly identifies which key was used
- KeyRing resolves keys by ID - no ambiguity or downgrade

**Residual Risk**: Low. Window is bounded by session lifetime.

### 9. Cookie Payload Overflow

**Attack**: Attacker crafts a request that causes the server to write oversized session data into a cookie, potentially causing data loss or undefined behavior.

**Mitigations**:

- Strict size enforcement: configurable max (default 2KB), hard cap 4KB
- Rejection (exception) rather than silent truncation
- Size check performed before encryption

**Residual Risk**: Negligible. Oversized payloads are rejected deterministically.

### 10. Session Metadata Privacy

**Attack**: Session metadata (IP, user agent) could be used for tracking or leaked through logging.

**Mitigations**:

- Metadata encrypted at rest alongside session data
- Fingerprint validator uses only stable, non-tracking attributes (Accept-Language, Accept-Encoding)
- No canvas fingerprinting, font enumeration, or similar tracking techniques
- Configurable validator attributes with privacy-aware defaults

**Residual Risk**: Low. Metadata collection is minimal and purpose-limited.

## Abuse Cases

### Abuse Case 1: Session Fixation via Pre-set Cookie

**Scenario**: Attacker sends victim a link with a pre-set session cookie.
**Expected Behavior**: Server rejects the uninitialized session ID (strict mode). After login, session ID is regenerated.
**Tested By**: Unit tests verifying regeneration on privilege change.

### Abuse Case 2: Encrypted Cookie Replay

**Scenario**: Attacker captures an encrypted session cookie and replays it after the user logs out.
**Expected Behavior**: Cookie handler validates `issued_at` against replay window. Server-side handlers (Redis/DB) have no matching session after logout/destroy.
**Tested By**: CookieHandler replay window tests.

### Abuse Case 3: AAD Tampering

**Scenario**: Attacker copies encrypted session data from one session to another.
**Expected Behavior**: AEAD decryption fails because AAD (session ID) doesn't match.
**Tested By**: SessionEncryption AAD mismatch tests.

### Abuse Case 4: Concurrent Session Flooding

**Scenario**: Attacker opens many concurrent sessions to maintain persistent access.
**Expected Behavior**: Session limit enforced atomically (Lua/transactions). Excess sessions rejected.
**Tested By**: Concurrent session limit tests with race condition simulation.

### Abuse Case 5: Key Rotation Exploitation

**Scenario**: Attacker captures a session encrypted with the old key and replays it after rotation.
**Expected Behavior**: Session decrypts successfully with previous key (by design - rotation window). After previous key removal, decryption fails.
**Tested By**: Key rotation tests in SessionEncryptionTest.

### Abuse Case 6: Oversized Cookie Payload

**Scenario**: Attacker or application logic writes excessive data to a cookie-based session.
**Expected Behavior**: `SecurityException::sessionPayloadTooLarge()` thrown. No partial write or truncation.
**Tested By**: CookieHandler size limit tests.

## Security Controls Summary

| Control                   | Implementation                          | Status      |
| ------------------------- | --------------------------------------- | ----------- |
| Session ID entropy        | 256-bit cryptographic random            | Implemented |
| Fixation protection       | Regenerate on privilege change          | Implemented |
| Cookie security flags     | HttpOnly, Secure, SameSite=Strict       | Implemented |
| Encryption at rest        | AEAD XChaCha20-Poly1305 via Keyring     | Implemented |
| AAD binding               | Session ID + handler + domain           | Implemented |
| Key rotation              | key_id header, KeyRing lookup           | Implemented |
| Concurrent session limits | Atomic Lua (Redis), transactions (DB)   | Implemented |
| User agent validation     | Normalized signature comparison         | Implemented |
| IP validation             | Subnet-aware matching (optional)        | Implemented |
| Fingerprint validation    | HMAC of stable headers (optional)       | Implemented |
| Replay mitigation         | issued_at timestamp (cookie handler)    | Implemented |
| Size enforcement          | Strict limits, reject on overflow       | Implemented |
| Key material protection   | sodium_memzero, serialization forbidden | Implemented |
