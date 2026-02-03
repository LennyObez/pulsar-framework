<?php

declare(strict_types=1);

namespace Pulsar\Integrity\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown for file integrity errors.
 *
 * Provides static factory methods for specific integrity error scenarios.
 */
#[Api]
final class IntegrityException extends RuntimeException
{
    /**
     * Manifest file was not found at the expected path.
     */
    #[NoDiscard]
    public static function manifestNotFound(string $path): self
    {
        return new self(sprintf('Integrity manifest not found at "%s"', $path));
    }

    /**
     * Manifest file exists but is corrupted or unparseable.
     */
    #[NoDiscard]
    public static function manifestCorrupted(string $path, string $reason): self
    {
        return new self(sprintf('Integrity manifest at "%s" is corrupted: %s', $path, $reason));
    }

    /**
     * HMAC signature verification failed for the manifest.
     */
    #[NoDiscard]
    public static function signatureInvalid(): self
    {
        return new self('Integrity manifest signature is invalid — the manifest may have been tampered with');
    }

    /**
     * Filesystem verification detected modifications or missing files.
     */
    #[NoDiscard]
    public static function verificationFailed(int $modified, int $missing): self
    {
        return new self(sprintf(
            'Integrity verification failed: %d modified file(s), %d missing file(s)',
            $modified,
            $missing,
        ));
    }

    /**
     * Manifest build process failed.
     */
    #[NoDiscard]
    public static function buildFailed(string $reason): self
    {
        return new self(sprintf('Failed to build integrity manifest: %s', $reason));
    }
}
