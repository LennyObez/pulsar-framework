<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Encryption;

use Pulsar\Api\Api;
use SodiumException;

use function random_bytes;
use function sodium_crypto_box;
use function sodium_crypto_box_keypair_from_secretkey_and_publickey;
use function sodium_crypto_box_open;
use function sodium_memzero;

use const SODIUM_CRYPTO_BOX_NONCEBYTES;

/**
 * Distributes group symmetric keys to participants via public-key encryption.
 *
 * For group conversations, a single symmetric key encrypts all messages.
 * This key is distributed to each participant by encrypting it with
 * crypto_box (X25519 + XSalsa20-Poly1305) using the sender's secret
 * key and each recipient's public key.
 *
 * The server stores only the encrypted key blobs: it cannot recover
 * the group key without a participant's secret key.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class GroupKeyDistributor
{
    /**
     * Encrypt the group key for a specific recipient.
     *
     * @param string $groupKey The symmetric group key (32 bytes)
     * @param string $senderSecretKey Sender's X25519 secret key (32 bytes)
     * @param string $recipientPublicKey Recipient's X25519 public key (32 bytes)
     * @return array{encryptedKey: string, nonce: string} Binary ciphertext and nonce
     *
     * @throws SodiumException
     */
    public function encryptForRecipient(
        string $groupKey,
        string $senderSecretKey,
        string $recipientPublicKey,
    ): array {
        $nonce = random_bytes(SODIUM_CRYPTO_BOX_NONCEBYTES);
        $keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $senderSecretKey,
            $recipientPublicKey,
        );

        $encryptedKey = sodium_crypto_box($groupKey, $nonce, $keyPair);

        sodium_memzero($keyPair);

        return [
            'encryptedKey' => $encryptedKey,
            'nonce' => $nonce,
        ];
    }

    /**
     * Decrypt the group key received from a sender.
     *
     * @param string $encryptedKey The encrypted group key blob
     * @param string $nonce The nonce used during encryption
     * @param string $recipientSecretKey Our X25519 secret key (32 bytes)
     * @param string $senderPublicKey Sender's X25519 public key (32 bytes)
     * @return string|false The decrypted group key, or false on failure
     *
     * @throws SodiumException
     */
    public function decryptGroupKey(
        string $encryptedKey,
        string $nonce,
        string $recipientSecretKey,
        string $senderPublicKey,
    ): string|false {
        $keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $recipientSecretKey,
            $senderPublicKey,
        );

        $result = sodium_crypto_box_open($encryptedKey, $nonce, $keyPair);

        sodium_memzero($keyPair);

        return $result;
    }

    /**
     * Distribute a group key to multiple recipients.
     *
     * @param string $groupKey The symmetric group key
     * @param string $senderSecretKey Sender's X25519 secret key
     * @param array<string, string> $recipientPublicKeys User ID => X25519 public key
     * @return array<string, array{encryptedKey: string, nonce: string}> User ID => encrypted blob
     *
     * @throws SodiumException
     */
    public function distributeToAll(
        string $groupKey,
        string $senderSecretKey,
        array $recipientPublicKeys,
    ): array {
        $result = [];

        foreach ($recipientPublicKeys as $userId => $publicKey) {
            $result[$userId] = $this->encryptForRecipient($groupKey, $senderSecretKey, $publicKey);
        }

        return $result;
    }
}
