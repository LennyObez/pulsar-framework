<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Encryption;

use Pulsar\Api\Api;
use SodiumException;

use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function sodium_memzero;

/**
 * Generates Ed25519 signing key pairs for user identity.
 *
 * Each user gets one identity key pair at account creation. The public key
 * is distributed; the secret key is wrapped and stored encrypted.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class KeyPairGenerator
{
    /**
     * Generate a new Ed25519 signing key pair.
     *
     * @return array{publicKey: string, secretKey: string} Raw binary keys
     *
     * @throws SodiumException
     */
    public function generate(): array
    {
        $keyPair = sodium_crypto_sign_keypair();

        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);

        sodium_memzero($keyPair);

        return [
            'publicKey' => $publicKey,
            'secretKey' => $secretKey,
        ];
    }
}
