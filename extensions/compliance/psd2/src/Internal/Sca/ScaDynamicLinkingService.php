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
use function hash_hmac;
use function random_bytes;
use function sprintf;
use function strlen;
use function substr;

/**
 * Default SCA dynamic linking implementation per PSD2 Art. 97(2).
 *
 * The authentication code is a keyed MAC — HMAC-SHA-256 under a per-deployment
 * server secret (a master-key-derived sub-key), over the transaction details
 * plus a server-generated per-challenge nonce that is never returned to the
 * client. Because the key and nonce are secret, the code cannot be recomputed
 * offline from the (public) transaction details, and any change to the amount,
 * currency, or payee invalidates it (dynamic linking).
 *
 * The code is a possession factor: an integrator MUST deliver it to the user
 * out of band (authenticator app, SMS, hardware token), never echo it back to
 * the party initiating the payment. {@see ScaChallenge::toArray()} deliberately
 * omits the nonce.
 */
#[Internal(reason: 'Use ScaDynamicLinkingServiceInterface')]
final readonly class ScaDynamicLinkingService implements ScaDynamicLinkingServiceInterface
{
    /**
     * @param string $secretKey Per-deployment secret (>= 32 bytes) keying the
     *        dynamic-linking HMAC. Derive it from the master key, never a constant.
     */
    public function __construct(
        private ScaChallengeStoreInterface $store,
        private ScaConfig $config,
        private string $secretKey,
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
        $nonce = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->config->challengeTimeoutSeconds));

        $authenticationCode = $this->generateDynamicLinkedCode(
            $challengeId,
            $amountMinorUnits,
            $currency,
            $payeeId,
            $nonce,
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
            nonce: $nonce,
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
            $challenge->nonce,
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
     * Generate a dynamic-linked authentication code: HMAC-SHA-256 under the
     * per-deployment secret over the transaction details and the per-challenge
     * nonce. The secret and nonce are what make it unforgeable — modifying any
     * transaction detail (or not knowing the key/nonce) yields a different code.
     */
    private function generateDynamicLinkedCode(
        string $challengeId,
        int $amountMinorUnits,
        string $currency,
        string $payeeId,
        string $nonce,
    ): string {
        if (strlen($this->secretKey) < 32) {
            // Fail closed: without a real key the code would be forgeable.
            throw Psd2Exception::scaSecretUnavailable();
        }

        $data = sprintf(
            '%s|%d|%s|%s|%s',
            $challengeId,
            $amountMinorUnits,
            $currency,
            $payeeId,
            $nonce,
        );

        $mac = hash_hmac('sha256', $data, $this->secretKey);

        return substr($mac, 0, $this->config->codeLength);
    }
}
