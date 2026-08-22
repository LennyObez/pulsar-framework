<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Signing;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function assert;
use function base64_decode;
use function base64_encode;
use function file_get_contents;
use function is_string;
use function realpath;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_verify_detached;
use function sprintf;
use function str_contains;
use function strlen;

use const SODIUM_CRYPTO_SIGN_BYTES;
use const SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
use const SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;

/**
 * Signs and verifies release artifacts using Ed25519 detached signatures.
 *
 * Uses libsodium's sodium_crypto_sign_detached for signing and
 * sodium_crypto_sign_verify_detached for verification (ADR-0006).
 *
 * All file paths are validated against directory traversal (CWE-22)
 * before any I/O operation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ArtifactSigner
{
    /**
     * Sign a file and return its base64-encoded Ed25519 signature.
     *
     * @param string $filePath Absolute path to the file to sign
     * @param string $secretKey Raw 64-byte Ed25519 secret key
     * @return string Base64-encoded detached signature
     *
     * @throws RuntimeException If the file cannot be read or the key is invalid
     */
    #[NoDiscard]
    public function sign(string $filePath, string $secretKey): string
    {
        $this->validateKeyLength($secretKey, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'secret');
        assert($secretKey !== ''); // Guaranteed by validateKeyLength (SODIUM_CRYPTO_SIGN_SECRETKEYBYTES > 0)

        $contents = $this->readFileSecurely($filePath);

        $signature = sodium_crypto_sign_detached($contents, $secretKey);

        return base64_encode($signature);
    }

    /**
     * Verify a file's Ed25519 signature.
     *
     * @param string $filePath Absolute path to the file to verify
     * @param string $signature Base64-encoded detached signature
     * @param string $publicKey Raw 32-byte Ed25519 public key
     * @return bool True if the signature is valid for the file contents and key
     *
     * @throws RuntimeException If the file cannot be read or inputs are malformed
     */
    public function verify(string $filePath, string $signature, string $publicKey): bool
    {
        // Validate path first (CWE-22) — reject traversal before any other work
        $contents = $this->readFileSecurely($filePath);

        $this->validateKeyLength($publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'public');
        assert($publicKey !== ''); // Guaranteed by validateKeyLength (SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES > 0)

        $rawSignature = base64_decode($signature, true);

        if ($rawSignature === false || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($rawSignature, $contents, $publicKey);
    }

    /**
     * Read a file after validating its path against traversal attacks (CWE-22).
     *
     * @throws RuntimeException If the path is invalid or the file cannot be read
     */
    private function readFileSecurely(string $filePath): string
    {
        // CWE-22: Reject paths containing traversal sequences before resolution
        if (str_contains($filePath, '..')) {
            throw new RuntimeException(sprintf(
                'Path traversal detected in file path: "%s"',
                $filePath,
            ));
        }

        $resolved = realpath($filePath);

        if ($resolved === false) {
            throw new RuntimeException(sprintf(
                'File does not exist or path cannot be resolved: "%s"',
                $filePath,
            ));
        }

        $contents = file_get_contents($resolved);

        if (!is_string($contents)) {
            throw new RuntimeException(sprintf(
                'Failed to read file: "%s"',
                $resolved,
            ));
        }

        return $contents;
    }

    /**
     * Validate that a key has the expected byte length.
     *
     * @throws RuntimeException If the key length is wrong
     */
    private function validateKeyLength(string $key, int $expectedLength, string $keyType): void
    {
        if (strlen($key) !== $expectedLength) {
            throw new RuntimeException(sprintf(
                'Invalid %s key length: expected %d bytes, got %d',
                $keyType,
                $expectedLength,
                strlen($key),
            ));
        }
    }
}
