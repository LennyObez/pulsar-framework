<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Themes;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\ThemesConfig;
use Pulsar\Extension\Cms\Themes\ProvenanceResult;
use Pulsar\Extension\Cms\Themes\ThemeProvenanceVerifierInterface;

use function base64_decode;
use function count;
use function file_exists;
use function file_get_contents;
use function hash_file;
use function sodium_crypto_sign_verify_detached;
use function strlen;

/**
 * Verifies theme package integrity using SHA-256 and authenticity using Ed25519 signatures.
 *
 * @psalm-api Bound to ThemeProvenanceVerifierInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use ThemeProvenanceVerifierInterface for public API')]
final readonly class ThemeProvenanceVerifier implements ThemeProvenanceVerifierInterface
{
    /** Expected Ed25519 signature length in bytes. */
    private const int SIGNATURE_LENGTH = 64;

    /** Expected Ed25519 public key length in bytes. */
    private const int PUBLIC_KEY_LENGTH = 32;

    public function __construct(
        private ThemesConfig $config,
        private LoggerInterface $logger,
    ) {}

    public function verify(string $archivePath, ?string $signaturePath = null): ProvenanceResult
    {
        // Step 1: Compute SHA-256 hash
        if (!file_exists($archivePath)) {
            return ProvenanceResult::failed('Archive file does not exist');
        }

        $computedHash = hash_file('sha256', $archivePath);

        if ($computedHash === false) {
            return ProvenanceResult::failed('Failed to compute archive hash');
        }

        // Step 2: Check for signature
        if ($signaturePath === null || !file_exists($signaturePath)) {
            $this->logger->info('Theme archive has no signature file', [
                'archive' => $archivePath,
            ]);

            return ProvenanceResult::unsigned();
        }

        // Step 3: Read and decode the signature
        $signatureRaw = file_get_contents($signaturePath);

        if ($signatureRaw === false) {
            return ProvenanceResult::failed('Failed to read signature file');
        }

        $signature = base64_decode($signatureRaw, strict: true);

        if ($signature === false || strlen($signature) !== self::SIGNATURE_LENGTH) {
            return ProvenanceResult::failed('Invalid signature format: expected 64-byte Ed25519 signature');
        }

        // Step 4: Read the archive contents for verification
        $archiveContents = file_get_contents($archivePath);

        if ($archiveContents === false) {
            return ProvenanceResult::failed('Failed to read archive for signature verification');
        }

        // Step 5: Try each trusted public key
        foreach ($this->config->trustedPublicKeys as $publicKeyEncoded) {
            $publicKey = base64_decode($publicKeyEncoded, strict: true);

            if ($publicKey === false || strlen($publicKey) !== self::PUBLIC_KEY_LENGTH) {
                $this->logger->warning('Skipping malformed trusted public key', [
                    'key_prefix' => substr($publicKeyEncoded, 0, 8) . '...',
                ]);

                continue;
            }

            // Constant-time signature verification via libsodium
            $valid = sodium_crypto_sign_verify_detached($signature, $archiveContents, $publicKey);

            if ($valid) {
                $this->logger->info('Theme signature verified successfully', [
                    'archive' => $archivePath,
                    'hash' => $computedHash,
                ]);

                return new ProvenanceResult(
                    hashValid: true,
                    signatureValid: true,
                    signaturePresent: true,
                );
            }
        }

        // Signature present but no matching key verified it
        $this->logger->warning('Theme signature verification failed: no trusted key matched', [
            'archive' => $archivePath,
            'trusted_key_count' => count($this->config->trustedPublicKeys),
        ]);

        return new ProvenanceResult(
            hashValid: true,
            signatureValid: false,
            signaturePresent: true,
            error: 'Signature does not match any trusted public key',
        );
    }
}
