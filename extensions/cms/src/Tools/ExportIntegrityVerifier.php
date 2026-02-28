<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

use function bin2hex;
use function hash_equals;
use function sodium_crypto_generichash;

use const SODIUM_CRYPTO_GENERICHASH_BYTES;

/**
 * Verifies integrity of exported CMS bundles using BLAKE2b hashing.
 *
 * @psalm-api Public utility called by name from MediaBundleImporter and import
 *            controllers to verify the evidence hash on uploaded bundles.
 */
#[Api(since: '1.0.0')]
final readonly class ExportIntegrityVerifier
{
    /**
     * Verify that a JSON content string matches an expected BLAKE2b hash.
     */
    public static function verify(string $jsonContent, string $expectedHash): bool
    {
        $computedHash = self::computeHash($jsonContent);

        return hash_equals($expectedHash, $computedHash);
    }

    /**
     * Compute a BLAKE2b hash of the given content.
     *
     * @return string Hex-encoded BLAKE2b hash
     */
    public static function computeHash(string $content): string
    {
        return bin2hex(sodium_crypto_generichash($content, '', SODIUM_CRYPTO_GENERICHASH_BYTES));
    }
}
