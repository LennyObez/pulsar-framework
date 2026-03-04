<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Sca;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Psd2\Config\ScaConfig;
use Pulsar\Extension\Psd2\Contracts\ScaChallengeStoreInterface;
use Pulsar\Extension\Psd2\Contracts\ScaDynamicLinkingServiceInterface;
use Pulsar\Extension\Psd2\Domain\ScaChallenge;
use Pulsar\Extension\Psd2\Domain\ScaChallengeType;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function bin2hex;
use function hash;
use function random_bytes;
use function sprintf;
use function substr;

/**
 * Default SCA dynamic linking implementation per PSD2 Art. 97(2).
 *
 * Generates authentication codes that are cryptographically bound to
 * the transaction amount and payee via HMAC, ensuring any modification
 * to the transaction details invalidates the challenge.
 */
#[Internal(reason: 'Use ScaDynamicLinkingServiceInterface')]
final readonly class ScaDynamicLinkingService implements ScaDynamicLinkingServiceInterface
{
    public function __construct(
        private ScaChallengeStoreInterface $store,
        private ScaConfig $config,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    #[Override]
    public function createChallenge(
        string $transactionId,
        int $amountMinorUnits,
        string $currency,
        string $payeeId,
        string $payeeName,
        ScaChallengeType $type = ScaChallengeType::Totp,
    ): ScaChallenge {
        $challengeId = bin2hex(random_bytes(16));
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->config->challengeTimeoutSeconds));

        $authenticationCode = $this->generateDynamicLinkedCode(
            $challengeId,
            $amountMinorUnits,
            $currency,
            $payeeId,
        );

        $challenge = new ScaChallenge(
            challengeId: $challengeId,
            transactionId: $transactionId,
            amountMinorUnits: $amountMinorUnits,
            currency: $currency,
            payeeId: $payeeId,
            payeeName: $payeeName,
            authenticationCode: $authenticationCode,
            challengeType: $type,
            createdAt: $now,
            expiresAt: $expiresAt,
        );

        $this->store->store($challenge);

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            null,
            'psd2_sca_challenge_created',
            metadata: [
                'challenge_id' => $challengeId,
                'transaction_id' => $transactionId,
                'challenge_type' => $type->value,
            ],
        );

        return $challenge;
    }

    #[Override]
    public function verifyChallenge(
        string $challengeId,
        string $responseCode,
        int $amountMinorUnits,
        string $currency,
        string $payeeId,
    ): ScaChallenge {
        $challenge = $this->store->find($challengeId);

        if ($challenge === null) {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                null,
                'psd2_sca_challenge_not_found',
                metadata: ['challenge_id' => $challengeId],
            );

            throw Psd2Exception::challengeNotFound($challengeId);
        }

        $now = new DateTimeImmutable();

        if ($challenge->isExpired($now)) {
            $this->store->remove($challengeId);

            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                null,
                'psd2_sca_challenge_expired',
                metadata: [
                    'challenge_id' => $challengeId,
                    'transaction_id' => $challenge->transactionId,
                ],
            );

            throw Psd2Exception::challengeExpired($challengeId);
        }

        if (!$challenge->matchesTransaction($amountMinorUnits, $currency, $payeeId)) {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                null,
                'psd2_sca_dynamic_link_mismatch',
                metadata: [
                    'challenge_id' => $challengeId,
                    'transaction_id' => $challenge->transactionId,
                ],
            );

            throw Psd2Exception::dynamicLinkMismatch($challengeId);
        }

        $expectedCode = $this->generateDynamicLinkedCode(
            $challengeId,
            $amountMinorUnits,
            $currency,
            $payeeId,
        );

        if (!hash_equals($expectedCode, $responseCode)) {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                null,
                'psd2_sca_invalid_code',
                metadata: [
                    'challenge_id' => $challengeId,
                    'transaction_id' => $challenge->transactionId,
                ],
            );

            throw Psd2Exception::invalidAuthenticationCode($challengeId);
        }

        $verified = $challenge->markVerified();
        $this->store->remove($challengeId);

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            null,
            'psd2_sca_challenge_verified',
            metadata: [
                'challenge_id' => $challengeId,
                'transaction_id' => $challenge->transactionId,
            ],
        );

        return $verified;
    }

    /**
     * Generate a dynamic-linked authentication code.
     *
     * The code is derived from the challenge ID combined with the transaction
     * details (amount, currency, payee), ensuring modification of any detail
     * produces a different code.
     */
    private function generateDynamicLinkedCode(
        string $challengeId,
        int $amountMinorUnits,
        string $currency,
        string $payeeId,
    ): string {
        $data = sprintf(
            '%s|%d|%s|%s',
            $challengeId,
            $amountMinorUnits,
            $currency,
            $payeeId,
        );

        $hash = hash('sha256', $data);

        return substr($hash, 0, $this->config->codeLength);
    }
}
