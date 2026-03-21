<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Psd2\Domain\ScaChallenge;
use Pulsar\Extension\Psd2\Domain\ScaChallengeType;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;

/**
 * SCA Dynamic Linking service per PSD2 Art. 97(2).
 *
 * Creates authentication challenges that are cryptographically linked
 * to the transaction amount and payee identity, ensuring that any
 * modification to the transaction details invalidates the challenge.
 * @api
 */
#[Api(since: '1.0.0')]
interface ScaDynamicLinkingServiceInterface
{
    /**
     * Create a new SCA challenge dynamically linked to a transaction.
     *
     * @param string $transactionId Unique transaction identifier
     * @param int $amountMinorUnits Transaction amount in minor currency units
     * @param string $currency ISO 4217 currency code
     * @param string $payeeId Unique payee identifier
     * @param string $payeeName Human-readable payee name
     * @param ScaChallengeType $type The challenge method
     *
     * @throws Psd2Exception On challenge generation failure
     */
    public function createChallenge(
        string $transactionId,
        int $amountMinorUnits,
        string $currency,
        string $payeeId,
        string $payeeName,
        ScaChallengeType $type = ScaChallengeType::Totp,
    ): ScaChallenge;

    /**
     * Verify a challenge response and validate transaction details still match.
     *
     * @param string $challengeId The challenge to verify
     * @param string $responseCode User-provided authentication code
     * @param int $amountMinorUnits Transaction amount to verify against
     * @param string $currency Currency to verify against
     * @param string $payeeId Payee to verify against
     *
     * @throws Psd2Exception On verification failure
     */
    public function verifyChallenge(
        string $challengeId,
        string $responseCode,
        int $amountMinorUnits,
        string $currency,
        string $payeeId,
    ): ScaChallenge;
}
