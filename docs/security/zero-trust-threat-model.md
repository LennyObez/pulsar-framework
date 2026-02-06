# Zero-Trust Architecture — Threat Model

## Asset

Zero-trust claims, policy decisions, device identities, trust scores, and signal data. The zero-trust module controls whether requests are granted, denied, or require step-up authentication. Compromise of any component can lead to unauthorized access, privilege escalation, or denial of service.

## Threat Actors

| Actor                       | Capability                                       | Motivation                                      |
| --------------------------- | ------------------------------------------------ | ----------------------------------------------- |
| Unauthenticated attacker    | Network access, public endpoints                 | Bypass authentication, gain unauthorized access |
| Authenticated attacker      | Valid session, limited permissions, known claims | Privilege escalation, policy bypass             |
| Compromised signal provider | Produces arbitrary claims with any confidence    | Grant access to unauthorized resources          |
| Network attacker (MITM)     | Traffic interception, header manipulation        | Signal spoofing, context manipulation           |
| Insider threat              | Application or infrastructure access             | Data exfiltration, policy manipulation          |
| Compromised device          | Valid device credentials, attestation history    | Impersonate legitimate device after compromise  |

## Attack Vectors and Mitigations

### 1. Claim Forgery

**Attack**: Attacker injects fabricated claims into the ClaimSet, bypassing signal providers entirely. For example, directly constructing a Claim with `confidence: 1.0` and `source: DeviceSignal` without actual device verification.

**Mitigations**:

- Claims are produced exclusively by registered `SignalProviderInterface` implementations — application code cannot inject claims into the evaluation pipeline
- The `ClaimSet` is constructed by the continuous verification layer from signal provider outputs, never from user input
- Policy engine receives the `ClaimSet` as a read-only value object; no mutation after construction
- Audit trail (`PolicyDecisionEvent`) captures the full claim snapshot for forensic verification

**Residual Risk**: Low. Requires compromise of the signal provider pipeline itself.

### 2. Source Spoofing

**Attack**: Attacker manipulates the `ClaimSource` tag on claims to make them appear from a trusted source (e.g., tagging a network-derived claim as `DeviceSignal` to satisfy a policy requiring device claims).

**Mitigations**:

- Each `SignalProviderInterface` is registered with a specific source type — the framework binds the source tag, not the provider
- `ClaimRequirement.allowedSources` enforces which sources are acceptable per policy rule
- Signal providers are resolved from the DI container, preventing runtime substitution
- Anomaly detection (`AnomalyDetectedEvent`) monitors for claims from unexpected sources

**Residual Risk**: Low. Requires DI container compromise or malicious extension registration.

### 3. Confidence Manipulation

**Attack**: Attacker influences signal inputs to artificially inflate confidence scores. For example, replaying known-good device fingerprints to achieve `confidence: 1.0` on device claims.

**Mitigations**:

- Confidence bounds are enforced in the `Claim` constructor: values outside 0.0-1.0 are rejected
- Signal providers must compute confidence from verifiable inputs, not user-supplied values
- `ClaimRequirement.minConfidence` sets per-rule thresholds — a single high-confidence claim cannot override missing claims
- Trust score computation uses weighted contributions (`ScoreExplanation`), preventing single-claim dominance
- Replay detection via timestamp comparison: claims older than the verification window are excluded

**Residual Risk**: Medium. Depends on signal provider implementation quality. Code review of signal providers is critical.

### 4. Policy Bypass

**Attack**: Attacker finds resource patterns or action strings that do not match any policy rule, defaulting to an overly permissive fallback.

**Mitigations**:

- Policy engine uses explicit-deny-by-default: unmatched resources receive `PolicyDecision::Deny`
- `PolicyRule` priority system ensures catch-all rules can be defined at lowest priority
- `PolicyEvaluationResult` includes `matchedRules` and `missingClaims` for audit inspection
- Every evaluation dispatches `PolicyDecisionEvent` for monitoring — deny-by-default violations are detectable

**Residual Risk**: Low. Requires misconfiguration of policy rules. Mitigated by configuration validation.

### 5. Device Impersonation

**Attack**: Attacker clones or replays device attestation data to impersonate a registered device. This includes extracting the device public key or replaying a previous attestation assertion.

**Mitigations**:

- Device verification uses challenge-response: `DeviceRegistryInterface.verify()` requires a fresh challenge and signed proof
- Device public keys are stored in the registry, not transmitted during verification
- `DeviceProofResult` carries confidence scores — partial verification (e.g., fingerprint match but attestation failure) produces reduced confidence
- `DeviceIdentity.lastVerifiedAt` enables staleness detection — devices not verified recently receive lower trust
- Device revocation via `DeviceRegistryInterface.revoke()` immediately invalidates compromised devices

**Residual Risk**: Medium. Depends on attestation implementation strength. WebAuthn attestation with hardware-backed keys significantly reduces risk.

### 6. Replay Attacks

**Attack**: Attacker captures a valid policy evaluation context (claims, resource, action) and replays it to gain access at a later time or from a different context.

**Mitigations**:

- Claims carry `timestamp` — the policy engine and continuous verification layer enforce freshness windows
- `SignalContext` is bound to the current HTTP request, session, and identity — replay from a different context produces different claims
- Continuous verification (`ContinuousVerificationInterface`) re-evaluates periodically, not relying on cached results
- Device challenge-response uses nonces that are valid for a single verification attempt

**Residual Risk**: Low. Time-bounded claims and per-request context binding prevent meaningful replay.

### 7. Step-Up Loop Exploitation

**Attack**: Attacker repeatedly triggers step-up authentication to either brute-force a secondary factor or cause denial of service by locking out legitimate users.

**Mitigations**:

- `StepUpConfig.maxAttempts` enforces a hard limit on attempts within a sliding window
- `StepUpConfig.cooldownSeconds` prevents rapid-fire attempts
- `StepUpConfig.lockoutSeconds` locks the identity after exceeding max attempts
- `StepUpState` tracks attempts immutably — each state transition returns a new instance, preventing state manipulation
- `StepUpLockoutEvent` dispatched on lockout, enabling alerting and security team notification
- `StepUpAttemptedEvent` dispatched on every attempt for monitoring frequency patterns

**Residual Risk**: Low. Rate limiting and lockout effectively prevent brute-force. Denial-of-service risk exists if an attacker can trigger lockout on behalf of a victim (requires session compromise).

### 8. Cross-Session Claim Leakage

**Attack**: Claims from one session leak into another session's evaluation, potentially granting elevated privileges based on a different user's trust posture.

**Mitigations**:

- `SignalContext` binds claims to a specific `sessionId` and `identityId`
- `ClaimSet` is constructed fresh for each evaluation from signal providers — no shared mutable state
- Signal providers are stateless: `evaluate()` must not cache state across requests
- `PolicyEvaluationResult.claimSnapshot` captures the exact claims used, enabling forensic cross-session comparison

**Residual Risk**: Negligible. Stateless evaluation design prevents cross-contamination by construction.

### 9. Signal Retention Privacy Violation

**Attack**: Retained signal data (IP addresses, device fingerprints, behavioral patterns) is accessed by unauthorized parties or retained beyond regulatory limits.

**Mitigations**:

- `SignalRetentionPolicy` enforces per-source retention limits with configurable durations
- `PseudonymizerInterface` enables irreversible pseudonymization of identifying data before storage
- `SignalRetentionPolicyChangedEvent` creates an audit trail for retention policy modifications
- `legalBasis` field on retention policies documents the regulatory justification for each signal type
- Zero-retention option (`retentionSeconds: 0`) available for signals that must not be stored

**Residual Risk**: Low. Depends on proper configuration of retention policies and correct pseudonymizer implementation.

### 10. Trust Score Gaming

**Attack**: Attacker systematically probes which claims contribute most to the trust score and focuses on artificially inflating those specific claims while ignoring others.

**Mitigations**:

- `TrustScoreResult` uses weighted contributions — no single claim can dominate the score
- `ScoreExplanation` provides per-claim breakdowns for audit, making gaming patterns detectable
- Score weights are configured server-side, not visible to clients
- Anomaly detection signals can flag unusual claim patterns (e.g., perfect device signal with zero network signal)
- Trust score threshold (`ZeroTrustConfig.trustScoreThreshold`) can be tuned per deployment

**Residual Risk**: Medium. Sophisticated attackers with knowledge of weight configuration could optimize claim inflation. Defense in depth through multiple signal requirements mitigates this.

## Abuse Case Scenarios

### Scenario A: Compromised Employee Device

**Situation**: An employee's laptop is compromised with malware that can read browser state and session cookies.

**Attack Chain**:

1. Malware extracts session cookie and device attestation data
2. Attacker replays session from a different network/location
3. Device signal succeeds (cloned attestation), but network and location signals produce low confidence
4. Policy engine evaluates: device claim satisfied, but network claim missing and location claim shows anomaly

**Expected Outcome**: `PolicyDecision::StepUp` — requires re-authentication via a channel the malware cannot access (e.g., mobile push notification). `AnomalyDetectedEvent` dispatched with `anomalyType: "location_jump"`.

### Scenario B: Privilege Escalation via Policy Gap

**Situation**: An application defines policy rules for `/admin/*` requiring high-confidence device and network claims, but a new admin endpoint `/api/admin/export` does not match the `/admin/*` pattern.

**Attack Chain**:

1. Authenticated user discovers `/api/admin/export` is not covered by admin policy rules
2. Attempts access with standard user claims

**Expected Outcome**: `PolicyDecision::Deny` — unmatched resources default to deny. `PolicyDecisionEvent` logs the attempt with zero matched rules, enabling detection of the policy gap during security review.

### Scenario C: Step-Up Brute Force

**Situation**: Attacker has compromised a user's primary credentials and attempts to brute-force the step-up authentication (e.g., TOTP code).

**Attack Chain**:

1. Attacker triggers step-up by accessing a protected resource
2. Submits rapid TOTP guesses
3. After 5 attempts (default `maxAttempts`), `StepUpState` triggers lockout
4. `StepUpLockoutEvent` dispatched

**Expected Outcome**: Identity locked out for 900 seconds (default). Security team alerted via event listener. Legitimate user can contact support for lockout reset.

### Scenario D: Signal Provider Compromise

**Situation**: A custom signal provider extension contains a vulnerability that allows arbitrary claim injection.

**Attack Chain**:

1. Attacker exploits vulnerability in third-party signal provider
2. Provider returns claims with `confidence: 1.0` for all claim types
3. Policy engine evaluates the inflated claims

**Expected Outcome**: Defense in depth applies. Policy rules with `allowedSources` reject claims from unexpected sources. Anomaly detection flags the sudden confidence spike. Multiple signal providers cross-validate — a single compromised provider cannot satisfy rules requiring claims from different sources.

### Scenario E: Data Retention Compliance Audit

**Situation**: A regulatory audit (GDPR, HIPAA) requires demonstrating that signal data is retained only as long as legally justified.

**Audit Evidence**:

1. `SignalRetentionPolicy` per source with documented `legalBasis`
2. `SignalRetentionPolicyChangedEvent` history showing all retention policy modifications with timestamps and responsible parties
3. `PseudonymizerInterface` implementation logs demonstrating irreversible pseudonymization
4. Zero-retention configuration for signal types without legal basis

**Expected Outcome**: Full audit trail demonstrating compliance with data minimization principles.
