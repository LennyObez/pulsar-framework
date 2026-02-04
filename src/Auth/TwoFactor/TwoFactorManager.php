<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use function assert;
use function bin2hex;

use Override;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Session\SessionInterface;
use Random\RandomException;

use function random_bytes;

use SensitiveParameter;

/**
 * Orchestrates TOTP and recovery code operations for two-factor authentication.
 *
 * Integrates replay guard, secret store, recovery code store, audit logging,
 * session regeneration, rate limiting, and observability event collection.
 */
final readonly class TwoFactorManager implements TwoFactorManagerInterface
{
    /**
     * Metadata keys allowed in audit and collector events (allowlist).
     */
    private const array ALLOWED_METADATA_KEYS = [
        'action', 'outcome', 'identity_id', 'purpose', 'reason',
        'code_index', 'provider', 'remaining_codes',
    ];

    public function __construct(
        private TotpGenerator $generator,
        private TotpVerifier $verifier,
        private RecoveryCodeGenerator $recoveryCodeGenerator,
        private RecoveryCodeVerifier $recoveryCodeVerifier,
        private string $issuer = 'Pulsar',
        private int $recoveryCodeCount = 8,
        private ?TotpReplayGuardInterface $replayGuard = null,
        private ?TotpSecretStoreInterface $secretStore = null,
        private ?RecoveryCodeHasher $recoveryCodeHasher = null,
        private ?RecoveryCodeStoreInterface $recoveryCodeStore = null,
        private ?AuditLoggerInterface $auditLogger = null,
        private ?SessionInterface $session = null,
        private ?TwoFactorRateLimiterInterface $rateLimiter = null,
        private ?AuthEventCollectorInterface $eventCollector = null,
    ) {}

    /**
     * @throws RandomException
     */
    #[Override]
    public function beginSetup(IdentityInterface $identity): array
    {
        $secret = $this->generator->generateSecret();
        $base32 = $this->generator->encodeSecretBase32($secret);
        $uri = $this->generator->provisioningUri($secret, $identity->displayName(), $this->issuer);
        $recoveryCodes = $this->recoveryCodeGenerator->generate($this->recoveryCodeCount);

        $this->emitAudit(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            $identity->id(),
            '2fa_setup_initiated',
        );

        return [
            'secret' => $secret,
            'secret_base32' => $base32,
            'provisioning_uri' => $uri,
            'recovery_codes' => $recoveryCodes,
        ];
    }

    #[Override]
    public function confirmSetup(
        string $identityId,
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        string $code,
    ): Confirm2faSetupResult {
        assert($identityId !== '', 'identityId must not be empty');

        $timeStep = $this->verifier->verify(
            $secret,
            $code,
            purpose: TwoFactorPurpose::Setup,
        );

        if ($timeStep === null) {
            $this->emitAudit(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                $identityId,
                '2fa_setup_confirmation_failed',
                ['reason' => 'invalid_code'],
            );

            return Confirm2faSetupResult::failure(VerifyReason::InvalidCode);
        }

        // Store the encrypted secret on successful confirmation
        $this->secretStore?->store($identityId, $secret);

        $this->emitAudit(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            $identityId,
            '2fa_setup_confirmed',
        );

        return Confirm2faSetupResult::success();
    }

    #[Override]
    public function verifyCode(
        string $identityId,
        #[SensitiveParameter]
        string $code,
        TwoFactorPurpose $purpose = TwoFactorPurpose::Login,
    ): Verify2faResult {
        assert($identityId !== '', 'identityId must not be empty');

        if ($this->rateLimiter !== null && !$this->rateLimiter->attempt($identityId, $purpose)) {
            $this->emitAudit(
                AuditEvent::Authentication,
                AuditOutcome::Denied,
                $identityId,
                '2fa_code_verification_failed',
                ['purpose' => $purpose->value, 'reason' => 'rate_limited'],
            );

            return Verify2faResult::failure(VerifyReason::RateLimited, $purpose);
        }

        if ($this->secretStore === null) {
            return Verify2faResult::failure(VerifyReason::NotEnrolled, $purpose);
        }

        $secret = $this->secretStore->retrieve($identityId);

        if ($secret === null) {
            return Verify2faResult::failure(VerifyReason::NotEnrolled, $purpose);
        }

        return $this->doVerify($identityId, $secret, $code, $purpose);
    }

    #[Override]
    public function verifyCodeWithSecret(
        string $identityId,
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        string $code,
        TwoFactorPurpose $purpose = TwoFactorPurpose::Login,
    ): Verify2faResult {
        assert($identityId !== '', 'identityId must not be empty');

        if ($this->rateLimiter !== null && !$this->rateLimiter->attempt($identityId, $purpose)) {
            $this->emitAudit(
                AuditEvent::Authentication,
                AuditOutcome::Denied,
                $identityId,
                '2fa_code_verification_failed',
                ['purpose' => $purpose->value, 'reason' => 'rate_limited'],
            );

            return Verify2faResult::failure(VerifyReason::RateLimited, $purpose);
        }

        return $this->doVerify($identityId, $secret, $code, $purpose);
    }

    #[Override]
    public function verifyRecoveryCode(
        string $identityId,
        #[SensitiveParameter]
        string $code,
        array $validCodes = [],
    ): int {
        // Store-backed mode: use hasher + store for atomic consume
        if ($this->recoveryCodeHasher !== null && $this->recoveryCodeStore !== null) {
            $hash = $this->recoveryCodeHasher->hash($code);
            $result = $this->recoveryCodeStore->consume($identityId, $hash);

            if ($result->consumed) {
                $this->emitAudit(
                    AuditEvent::Authentication,
                    AuditOutcome::Success,
                    $identityId,
                    '2fa_recovery_code_used',
                    ['code_index' => (string) $result->codeIndex],
                );

                // Regenerate session on recovery code use (privilege escalation defense)
                $this->session?->regenerate();

                return $result->codeIndex;
            }

            $action = $result->reason === ConsumeReason::AlreadyUsed
                ? '2fa_recovery_code_replayed'
                : '2fa_recovery_code_failed';
            $outcome = $result->reason === ConsumeReason::AlreadyUsed
                ? AuditOutcome::Denied
                : AuditOutcome::Failure;

            $this->emitAudit(
                AuditEvent::Authentication,
                $outcome,
                $identityId,
                $action,
                ['reason' => $result->reason->value],
            );

            return -1;
        }

        // Legacy mode: plaintext comparison
        $index = $this->recoveryCodeVerifier->verify($code, $validCodes);

        if ($index >= 0) {
            $this->emitAudit(
                AuditEvent::Authentication,
                AuditOutcome::Success,
                $identityId,
                '2fa_recovery_code_used',
                ['code_index' => (string) $index],
            );
        } else {
            $this->emitAudit(
                AuditEvent::Authentication,
                AuditOutcome::Failure,
                $identityId,
                '2fa_recovery_code_failed',
            );
        }

        return $index;
    }

    /**
     * Rotate recovery codes: generate new set, store, invalidate old set.
     *
     * @throws RandomException
     */
    public function rotateRecoveryCodes(string $identityId): RecoveryCodeRotationResult
    {
        assert($identityId !== '', 'identityId must not be empty');

        $plaintextCodes = $this->recoveryCodeGenerator->generate($this->recoveryCodeCount);
        $codeHashes = $this->recoveryCodeHasher !== null
            ? $this->recoveryCodeHasher->hashAll($plaintextCodes)
            : [];

        $set = new RecoveryCodeSet(
            setId: bin2hex(random_bytes(16)),
            codeHashes: $codeHashes,
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: time(),
        );

        $this->recoveryCodeStore?->store($identityId, $set);

        $this->emitAudit(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            $identityId,
            '2fa_recovery_codes_rotated',
        );

        return new RecoveryCodeRotationResult($set, $plaintextCodes);
    }

    private function doVerify(
        string $identityId,
        string $secret,
        string $code,
        TwoFactorPurpose $purpose,
    ): Verify2faResult {
        $timeStep = $this->verifier->verify(
            $secret,
            $code,
            replayGuard: $this->replayGuard,
            identityId: $identityId,
            purpose: $purpose,
        );

        if ($timeStep === null) {
            $this->emitAudit(
                AuditEvent::Authentication,
                AuditOutcome::Failure,
                $identityId,
                '2fa_code_verification_failed',
                ['purpose' => $purpose->value, 'reason' => 'invalid_code'],
            );

            return Verify2faResult::failure(VerifyReason::InvalidCode, $purpose);
        }

        // Session regeneration on successful 2FA (privilege escalation defense)
        $this->session?->regenerate();

        $this->emitAudit(
            AuditEvent::Authentication,
            AuditOutcome::Success,
            $identityId,
            '2fa_code_verified',
            ['purpose' => $purpose->value],
        );

        return Verify2faResult::success($purpose, $timeStep);
    }

    /**
     * Emit an audit event and collector event with allowlisted metadata.
     *
     * @param array<string, string> $metadata
     */
    private function emitAudit(
        AuditEvent $event,
        AuditOutcome $outcome,
        string $identityId,
        string $action,
        array $metadata = [],
    ): void {
        // Enforce metadata allowlist
        $sanitized = array_intersect_key($metadata, array_flip(self::ALLOWED_METADATA_KEYS));

        $this->auditLogger?->log(
            $event,
            $outcome,
            $identityId,
            $action,
            metadata: $sanitized,
        );

        $this->eventCollector?->recordTwoFactorEvent(
            $action,
            $outcome->value,
            $identityId,
            $sanitized,
        );
    }
}
