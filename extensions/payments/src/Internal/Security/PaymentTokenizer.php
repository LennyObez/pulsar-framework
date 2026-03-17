<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Security;

use NoDiscard;
use Pulsar\Api\Internal;
use RuntimeException;

use function bin2hex;
use function getenv;
use function random_bytes;
use function sodium_bin2hex;
use function sodium_crypto_generichash;
use function sodium_hex2bin;
use function strlen;
use function substr;

use const SODIUM_CRYPTO_GENERICHASH_KEYBYTES;

/**
 * Payment tokenization service.
 *
 * Generates opaque, non-reversible tokens for payment method references.
 * Raw card numbers, bank account numbers, and other sensitive data
 * are never stored: only their tokenized representations.
 *
 * SEC-CRYPTO-02: purchase tokens and fingerprints are derived using
 * BLAKE2b keyed (libsodium) with a context key sourced from the
 * `PULSAR_PCI_TOKENIZER_KEY` environment variable. Non-keyed SHA-256
 * left the indices vulnerable to dictionary correlation across
 * environments (a fingerprint seen in dev would match one in prod for
 * the same card). The keyed construction binds each fingerprint to the
 * deployment, so a leaked index cannot be replayed without the key.
 *
 * PCI-DSS v4.0.1 Requirement 3: Protect stored cardholder data.
 */
#[Internal]
final readonly class PaymentTokenizer
{
    private const string KEY_ENV_VAR = 'PULSAR_PCI_TOKENIZER_KEY';

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
     * Hash a purchase token for storage (one-way, BLAKE2b keyed).
     *
     * Used for mobile in-app purchase token deduplication.
     */
    #[NoDiscard]
    public static function hashPurchaseToken(string $token): string
    {
        return sodium_bin2hex(sodium_crypto_generichash($token, self::resolveKey('purchase'), 32));
    }

    /**
     * Generate a fingerprint for a payment method (BLAKE2b keyed).
     *
     * Used to detect duplicate payment methods without storing raw data.
     * The fingerprint is bound to the deployment via the configured tokenizer
     * key, preventing cross-environment correlation.
     */
    #[NoDiscard]
    public static function fingerprint(string $methodType, string $last4, string $expiryMonth, string $expiryYear): string
    {
        $input = "$methodType:$last4:$expiryMonth:$expiryYear";

        return substr(sodium_bin2hex(sodium_crypto_generichash($input, self::resolveKey('fingerprint'), 32)), 0, 32);
    }

    /**
     * Derive a per-context BLAKE2b key from the configured tokenizer key.
     *
     * The base key is read from PULSAR_PCI_TOKENIZER_KEY as a hex-encoded
     * 32-byte secret. A context label (`purchase`, `fingerprint`, …) is mixed
     * in with BLAKE2b so a leak of one derived key does not compromise the
     * others.
     */
    private static function resolveKey(string $context): string
    {
        $hexBase = getenv(self::KEY_ENV_VAR);

        if ($hexBase === false || $hexBase === '') {
            throw new RuntimeException(
                'PULSAR_PCI_TOKENIZER_KEY is not set. Generate a 32-byte key with '
                . '`bin2hex(random_bytes(32))` and configure it before tokenizing payment data.',
            );
        }

        if (strlen($hexBase) !== SODIUM_CRYPTO_GENERICHASH_KEYBYTES * 2) {
            throw new RuntimeException(
                'PULSAR_PCI_TOKENIZER_KEY must be ' . (SODIUM_CRYPTO_GENERICHASH_KEYBYTES * 2)
                . ' hex characters (32 bytes); got ' . strlen($hexBase) . '.',
            );
        }

        $baseKey = sodium_hex2bin($hexBase);

        return sodium_crypto_generichash($context, $baseKey, SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
    }
}
