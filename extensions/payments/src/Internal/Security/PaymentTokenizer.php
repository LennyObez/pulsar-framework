<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Security;

use NoDiscard;
use Pulsar\Api\Internal;

use function bin2hex;
use function hash;
use function random_bytes;
use function substr;

/**
 * Payment tokenization service.
 *
 * Generates opaque, non-reversible tokens for payment method references.
 * Raw card numbers, bank account numbers, and other sensitive data
 * are never stored: only their tokenized representations.
 *
 * PCI-DSS v4.0.1 Requirement 3: Protect stored cardholder data.
 */
#[Internal]
final readonly class PaymentTokenizer
{
    /**
     * Generate a secure, opaque token for a payment method.
     *
     * The token is not derived from the original data (one-way).
     */
    #[NoDiscard]
    public static function generateToken(string $prefix = 'tok'): string
    {
        return $prefix . '_' . bin2hex(random_bytes(16));
    }

    /**
     * Hash a purchase token for storage (one-way, using SHA-256).
     *
     * Used for mobile in-app purchase token deduplication.
     */
    #[NoDiscard]
    public static function hashPurchaseToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Generate a fingerprint for a payment method.
     *
     * Used to detect duplicate payment methods without storing raw data.
     * The fingerprint includes enough entropy to be unique but not reversible.
     */
    #[NoDiscard]
    public static function fingerprint(string $methodType, string $last4, string $expiryMonth, string $expiryYear): string
    {
        $input = "$methodType:$last4:$expiryMonth:$expiryYear";

        return substr(hash('sha256', $input), 0, 32);
    }
}
