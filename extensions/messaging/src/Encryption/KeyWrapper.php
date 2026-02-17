<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Encryption;

use Pulsar\Api\Api;
use SodiumException;

use function random_bytes;
use function sodium_crypto_pwhash;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function sodium_memzero;

use const SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13;
use const SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
use const SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
use const SODIUM_CRYPTO_PWHASH_SALTBYTES;
use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * Wraps private keys with a password-derived key for secure storage.
 *
 * The user's private key is encrypted with a key derived from their
 * password via Argon2id. This allows the server to store the wrapped
 * key without being able to read the private key: only the user's
 * password can unwrap it.
 *
 * Password change: re-wrap with new password-derived key. No re-encryption
 * of message history needed since the private key itself doesn't change.
 */
#[Api(since: '1.0.0')]
final readonly class KeyWrapper
{
    /**
     * Wrap (encrypt) a private key using a password-derived key.
     *
     * @param string $privateKey The secret key to wrap (binary)
     * @param string $password The user's password
     * @return array{wrappedKey: string, nonce: string, salt: string} All binary
     *
     * @throws SodiumException
     */
    public function wrap(string $privateKey, string $password): array
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $derivedKey = $this->deriveKey($password, $salt);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $wrappedKey = sodium_crypto_secretbox($privateKey, $nonce, $derivedKey);

        sodium_memzero($derivedKey);

        return [
            'wrappedKey' => $wrappedKey,
            'nonce' => $nonce,
            'salt' => $salt,
        ];
    }

    /**
     * Unwrap (decrypt) a private key using the user's password.
     *
     * @param string $wrappedKey The encrypted private key (binary)
     * @param string $nonce The nonce used during wrapping (binary)
     * @param string $salt The salt used for key derivation (binary)
     * @param string $password The user's password
     * @return string|false The unwrapped private key, or false if password is wrong
     *
     * @throws SodiumException
     */
    public function unwrap(string $wrappedKey, string $nonce, string $salt, string $password): string|false
    {
        $derivedKey = $this->deriveKey($password, $salt);
        $result = sodium_crypto_secretbox_open($wrappedKey, $nonce, $derivedKey);

        sodium_memzero($derivedKey);

        return $result;
    }

    /**
     * Re-wrap a private key with a new password.
     *
     * Used during password change: unwrap with old password, wrap with new.
     *
     * @param string $wrappedKey Current wrapped key
     * @param string $nonce Current nonce
     * @param string $salt Current salt
     * @param string $oldPassword Current password
     * @param string $newPassword New password
     * @return array{wrappedKey: string, nonce: string, salt: string}|false False if old password is wrong
     *
     * @throws SodiumException
     */
    public function rewrap(
        string $wrappedKey,
        string $nonce,
        string $salt,
        string $oldPassword,
        string $newPassword,
    ): array|false {
        $privateKey = $this->unwrap($wrappedKey, $nonce, $salt, $oldPassword);

        if ($privateKey === false) {
            return false;
        }

        $result = $this->wrap($privateKey, $newPassword);
        sodium_memzero($privateKey);

        return $result;
    }

    /**
     * @throws SodiumException
     */
    private function deriveKey(string $password, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }
}
