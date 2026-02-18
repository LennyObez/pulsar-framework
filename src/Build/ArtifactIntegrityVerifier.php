<?php

declare(strict_types=1);

namespace Pulsar\Build;

use Pulsar\Api\Api;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;
use SodiumException;

use function file_get_contents;
use function hash;
use function hash_equals;
use function is_file;
use function json_encode;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Verifies build artifact integrity against the build manifest.
 *
 * Computes SHA-256 hashes of each artifact and compares them against
 * the hashes stored in the build manifest. Optionally verifies the
 * manifest signature using the central Keyring.
 */
#[Api(since: '1.0.0')]
final class ArtifactIntegrityVerifier
{
    private const int SIGNING_SUB_KEY_ID = 8;
    private const string SIGNING_CONTEXT = 'bld_sign';

    /**
     * Verify all artifacts listed in the manifest.
     *
     * @param BuildManifest $manifest The build manifest to verify against
     * @param string $cacheDir Directory containing the artifacts
     * @return VerificationResult
     */
    public function verify(BuildManifest $manifest, string $cacheDir): VerificationResult
    {
        $entries = [];
        $errors = [];

        foreach ($manifest->artifacts as $key => $artifact) {
            $filePath = $cacheDir . DIRECTORY_SEPARATOR . $artifact->path;

            if (!is_file($filePath)) {
                $entries[$key] = VerificationStatus::Missing;
                $errors[] = sprintf('Artifact "%s" is missing: %s', $key, $artifact->path);

                continue;
            }

            $contents = file_get_contents($filePath);

            if ($contents === false) {
                $entries[$key] = VerificationStatus::Missing;
                $errors[] = sprintf('Artifact "%s" could not be read: %s', $key, $artifact->path);

                continue;
            }

            $actualHash = hash('sha256', $contents);

            if (!hash_equals($artifact->hash, $actualHash)) {
                $entries[$key] = VerificationStatus::Modified;
                $errors[] = sprintf(
                    'Artifact "%s" has been modified (expected %s, got %s)',
                    $key,
                    $artifact->hash,
                    $actualHash,
                );

                continue;
            }

            $entries[$key] = VerificationStatus::Ok;
        }

        if ($errors !== []) {
            return VerificationResult::fail($entries, $errors);
        }

        return VerificationResult::pass($entries);
    }

    /**
     * Verify the manifest signature using the central Keyring.
     *
     * @throws SodiumException If cryptographic operations fail
     */
    public function verifySignature(
        BuildManifest $manifest,
        HmacInterface $hmac,
        KeyProviderInterface $keyProvider,
    ): bool {
        if ($manifest->signature === null) {
            return false;
        }

        $signingKey = $keyProvider->deriveSubKey(self::SIGNING_SUB_KEY_ID, self::SIGNING_CONTEXT);
        $canonical = $this->canonicalize($manifest);

        return $hmac->verifyHex($canonical, $manifest->signature, $signingKey);
    }

    /**
     * Sign a build manifest using the central Keyring.
     *
     * @throws SodiumException If cryptographic operations fail
     */
    public function sign(
        BuildManifest $manifest,
        HmacInterface $hmac,
        KeyProviderInterface $keyProvider,
    ): string {
        $signingKey = $keyProvider->deriveSubKey(self::SIGNING_SUB_KEY_ID, self::SIGNING_CONTEXT);
        $canonical = $this->canonicalize($manifest);

        return $hmac->computeHex($canonical, $signingKey);
    }

    /**
     * Build a canonical JSON representation for signing.
     *
     * Includes all fields except the signature to allow round-trip
     * sign-then-verify without circularity.
     */
    private function canonicalize(BuildManifest $manifest): string
    {
        $artifacts = [];

        foreach ($manifest->artifacts as $key => $artifact) {
            $artifacts[$key] = [
                'path' => $artifact->path,
                'hash' => $artifact->hash,
                'size' => $artifact->size,
            ];
        }

        $data = [
            'version' => $manifest->version,
            'algorithm' => $manifest->algorithm,
            'artifacts' => $artifacts,
            'content_hashes' => $manifest->contentHashes,
        ];

        /** @var non-empty-string $json */
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json;
    }
}
