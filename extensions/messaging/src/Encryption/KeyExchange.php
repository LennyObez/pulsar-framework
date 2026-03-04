<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Encryption;

use Pulsar\Api\Api;
use SodiumException;

use function sodium_crypto_box_keypair;
use function sodium_crypto_box_publickey;
use function sodium_crypto_box_secretkey;
use function sodium_crypto_scalarmult;
use function sodium_memzero;

/**
 * X25519 Diffie-Hellman key exchange for deriving shared secrets.
 *
 * Used to establish a shared symmetric key between two participants
 * without either party revealing their secret key.
 */
#[Api(since: '1.0.0')]
final readonly class KeyExchange
{
    /**
     * Generate an X25519 key pair for key exchange.
     *
     * @return array{publicKey: string, secretKey: string} Raw binary keys
     *
     * @throws SodiumException
     */
    public function generateKeyPair(): array
    {
        $keyPair = sodium_crypto_box_keypair();

        $publicKey = sodium_crypto_box_publickey($keyPair);
        $secretKey = sodium_crypto_box_secretkey($keyPair);

        sodium_memzero($keyPair);

        return [
            'publicKey' => $publicKey,
            'secretKey' => $secretKey,
        ];
    }

    /**
     * Derive a shared secret from our secret key and their public key.
     *
     * Uses X25519 scalar multiplication (Curve25519 Diffie-Hellman).
     * Both parties derive the same shared secret independently.
     *
     * @param string $ourSecretKey Our X25519 secret key (32 bytes)
     * @param string $theirPublicKey Their X25519 public key (32 bytes)
     * @return string Shared secret (32 bytes)
     *
     * @throws SodiumException
     */
    public function deriveSharedSecret(string $ourSecretKey, string $theirPublicKey): string
    {
        return sodium_crypto_scalarmult($ourSecretKey, $theirPublicKey);
    }
}
