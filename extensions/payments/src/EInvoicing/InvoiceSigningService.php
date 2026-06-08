<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Internal;

use function assert;
use function sodium_bin2hex;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_publickey_from_secretkey;
use function sodium_crypto_sign_verify_detached;
use function sodium_hex2bin;

/**
 * Ed25519 invoice XML signing service using libsodium.
 *
 * Signs invoice XML documents with Ed25519 detached signatures for
 * cryptographic non-repudiation and tamper detection. This follows
 * the Pulsar security policy of using libsodium as the primary
 * cryptographic provider (ADR-0006).
 *
 * Key format: 64-byte Ed25519 secret key encoded as hex (128 hex chars).
 * The public key is derived from the secret key automatically.
 */
#[Internal(reason: 'Invoice signing service')]
final readonly class InvoiceSigningService
{
    /**
     * Sign an invoice XML document with an Ed25519 private key.
     *
     * @param string $xml The XML document to sign
     * @param string $privateKeyHex Ed25519 secret key as hex (128 hex chars = 64 bytes)
     */
    #[NoDiscard]
    public function sign(string $xml, string $privateKeyHex): SignedInvoice
    {
        $privateKey = sodium_hex2bin($privateKeyHex);
        assert($privateKey !== '');
        $publicKey = sodium_crypto_sign_publickey_from_secretkey($privateKey);

        $signature = sodium_crypto_sign_detached($xml, $privateKey);

        return new SignedInvoice(
            xml: $xml,
            signatureHex: sodium_bin2hex($signature),
            publicKeyHex: sodium_bin2hex($publicKey),
            signedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Verify an Ed25519 detached signature against an XML document.
     *
     * @param string $xml The XML document that was signed
     * @param string $signatureHex Detached signature as hex (128 hex chars = 64 bytes)
     * @param string $publicKeyHex Ed25519 public key as hex (64 hex chars = 32 bytes)
     */
    public function verify(string $xml, string $signatureHex, string $publicKeyHex): bool
    {
        $signature = sodium_hex2bin($signatureHex);
        assert($signature !== '');
        $publicKey = sodium_hex2bin($publicKeyHex);
        assert($publicKey !== '');

        return sodium_crypto_sign_verify_detached($signature, $xml, $publicKey);
    }
}
