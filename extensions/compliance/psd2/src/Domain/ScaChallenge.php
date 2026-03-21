<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Represents an SCA challenge dynamically linked to a transaction.
 *
 * Per PSD2 Art. 97(2), the authentication code must be linked to
 * the transaction amount and payee, ensuring any modification
 * invalidates the challenge.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ScaChallenge
{
    public function __construct(
        public string $challengeId,
        public string $transactionId,
        public int $amountMinorUnits,
        public string $currency,
        public string $payeeId,
        public string $payeeName,
        public string $authenticationCode,
        public ScaChallengeType $challengeType,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public bool $verified = false,
    ) {}

    /**
     * Check whether this challenge has expired.
     */
    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }

    /**
     * Mark this challenge as verified.
     */
    #[NoDiscard]
    public function markVerified(): self
    {
        return clone($this, ['verified' => true]);
    }

    /**
     * Verify that the given transaction details match the dynamic link.
     *
     * Returns true only when amount, currency, and payee match exactly.
     */
    public function matchesTransaction(int $amountMinorUnits, string $currency, string $payeeId): bool
    {
        return $this->amountMinorUnits === $amountMinorUnits
            && $this->currency === $currency
            && $this->payeeId === $payeeId;
    }

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'challenge_id' => $this->challengeId,
            'transaction_id' => $this->transactionId,
            'amount_minor_units' => $this->amountMinorUnits,
            'currency' => $this->currency,
            'payee_id' => $this->payeeId,
            'payee_name' => $this->payeeName,
            'authentication_code' => $this->authenticationCode,
            'challenge_type' => $this->challengeType->value,
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s.uP'),
            'expires_at' => $this->expiresAt->format('Y-m-d\TH:i:s.uP'),
            'verified' => $this->verified,
        ];
    }
}
