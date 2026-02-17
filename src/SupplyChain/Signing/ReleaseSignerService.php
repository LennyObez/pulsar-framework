<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Signing;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Internal;
use RuntimeException;

use function array_merge;
use function assert;
use function base64_encode;
use function glob;
use function is_array;
use function realpath;
use function sodium_crypto_sign_publickey_from_secretkey;
use function sprintf;
use function str_contains;
use function strlen;

/**
 * Signs all release artifacts in a directory and produces signature manifests.
 *
 * Scans for PHAR, ZIP, and TAR archive files and signs each one
 * with the provided Ed25519 secret key.
 *
 * File paths are validated to prevent directory traversal (CWE-22).
 */
#[Internal(reason: 'Release signing orchestration')]
final readonly class ReleaseSignerService
{
    /** @var list<string> Glob patterns for release artifact types */
    private const array ARTIFACT_PATTERNS = ['*.phar', '*.zip', '*.tar', '*.tar.gz', '*.tgz'];

    public function __construct(
        private ArtifactSigner $signer,
    ) {}

    /**
     * Sign all release artifacts in a directory.
     *
     * @param string $directory Absolute path to the directory containing artifacts
     * @param string $secretKey Raw 64-byte Ed25519 secret key
     * @return list<SignatureManifest> Manifest entries for all signed artifacts
     *
     * @throws RuntimeException If the directory is invalid or signing fails
     */
    #[NoDiscard]
    public function signDirectory(string $directory, string $secretKey): array
    {
        $resolvedDir = $this->resolveDirectory($directory);
        assert($secretKey !== ''); // Caller must provide a valid Ed25519 secret key
        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
        $publicKeyB64 = base64_encode($publicKey);

        $artifacts = $this->findArtifacts($resolvedDir);
        $manifests = [];

        foreach ($artifacts as $artifactPath) {
            $signature = $this->signer->sign($artifactPath, $secretKey);

            // Store path relative to the signing directory
            $relativePath = substr($artifactPath, strlen($resolvedDir) + 1);

            $manifests[] = new SignatureManifest(
                artifactPath: $relativePath,
                signature: $signature,
                publicKey: $publicKeyB64,
                timestamp: new DateTimeImmutable(),
                algorithm: 'ed25519',
            );
        }

        return $manifests;
    }

    /**
     * Verify all signatures in a manifest against artifacts in a directory.
     *
     * @param string $directory Absolute path to the directory containing artifacts
     * @param list<SignatureManifest> $manifests Manifests to verify
     * @param string $publicKey Raw 32-byte Ed25519 public key
     * @return array{valid: list<string>, invalid: list<string>, missing: list<string>}
     */
    #[NoDiscard]
    public function verifyDirectory(string $directory, array $manifests, string $publicKey): array
    {
        $resolvedDir = $this->resolveDirectory($directory);

        $valid = [];
        $invalid = [];
        $missing = [];

        foreach ($manifests as $manifest) {
            $fullPath = $resolvedDir . '/' . $manifest->artifactPath;

            // CWE-22: Ensure resolved path stays within the target directory
            $resolvedPath = realpath($fullPath);

            if ($resolvedPath === false) {
                $missing[] = $manifest->artifactPath;

                continue;
            }

            if (!str_starts_with($resolvedPath, $resolvedDir)) {
                $invalid[] = $manifest->artifactPath;

                continue;
            }

            $isValid = $this->signer->verify($resolvedPath, $manifest->signature, $publicKey);

            if ($isValid) {
                $valid[] = $manifest->artifactPath;
            } else {
                $invalid[] = $manifest->artifactPath;
            }
        }

        return ['valid' => $valid, 'invalid' => $invalid, 'missing' => $missing];
    }

    /**
     * Find release artifacts in a directory.
     *
     * @return list<string> Absolute paths to artifact files
     */
    private function findArtifacts(string $directory): array
    {
        $files = [];

        foreach (self::ARTIFACT_PATTERNS as $pattern) {
            $matches = glob($directory . '/' . $pattern);

            if (is_array($matches)) {
                $files = array_merge($files, $matches);
            }
        }

        return $files;
    }

    /**
     * Resolve and validate a directory path (CWE-22).
     *
     * @throws RuntimeException If the directory is invalid or contains traversal sequences
     */
    private function resolveDirectory(string $directory): string
    {
        if (str_contains($directory, '..')) {
            throw new RuntimeException(sprintf(
                'Path traversal detected in directory path: "%s"',
                $directory,
            ));
        }

        $resolved = realpath($directory);

        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException(sprintf(
                'Directory does not exist or cannot be resolved: "%s"',
                $directory,
            ));
        }

        return $resolved;
    }
}
