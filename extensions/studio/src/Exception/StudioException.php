<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Exception;

use NoDiscard;
use Pulsar\Api\Internal;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Base exception for all Studio-related errors.
 *
 * Provides static factory methods for specific Studio error scenarios.
 */
#[Internal]
final class StudioException extends RuntimeException
{
    /**
     * Studio storage is not writable.
     */
    #[NoDiscard]
    public static function storageNotWritable(string $path): self
    {
        return new self(sprintf('Studio storage path is not writable: %s', $path));
    }

    /**
     * Studio storage is busy after maximum retry attempts.
     */
    #[NoDiscard]
    public static function storeBusy(int $attempts, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Studio storage busy after %d attempts', $attempts),
            previous: $previous,
        );
    }

    /**
     * Export requires decryption key but it is not available.
     */
    #[NoDiscard]
    public static function exportRequiresDecryptionKey(): self
    {
        return new self(
            'Cannot export: events are encrypted at rest but PULSAR_MASTER_KEY is not set. '
            . 'The export archive requires decrypted plaintext payloads for chain verification '
            . 'by external parties. Provide the master key to proceed.',
        );
    }

    /**
     * Schema migration failed.
     */
    #[NoDiscard]
    public static function schemaFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Studio schema error: %s', $reason),
            previous: $previous,
        );
    }

    /**
     * Evidence chain verification failed.
     */
    #[NoDiscard]
    public static function chainBroken(int $linkIndex, string $reason): self
    {
        return new self(
            sprintf('Evidence chain broken at link %d: %s', $linkIndex, $reason),
        );
    }

    /**
     * Studio is not enabled.
     */
    #[NoDiscard]
    public static function notEnabled(): self
    {
        return new self('Studio is not enabled');
    }

    /**
     * Studio server failed to start.
     */
    #[NoDiscard]
    public static function serverStartFailed(string $reason): self
    {
        return new self(sprintf('Studio server failed to start: %s', $reason));
    }

    /**
     * Access denied to Studio.
     */
    #[NoDiscard]
    public static function accessDenied(string $reason = 'unauthorized'): self
    {
        return new self(sprintf('Studio access denied: %s', $reason));
    }

    /**
     * Invalid Studio configuration.
     */
    #[NoDiscard]
    public static function invalidConfig(string $reason): self
    {
        return new self(sprintf('Invalid Studio configuration: %s', $reason));
    }

    /**
     * Archive file is invalid or corrupted.
     */
    #[NoDiscard]
    public static function invalidArchive(string $reason): self
    {
        return new self(sprintf('Invalid Studio archive: %s', $reason));
    }

    /**
     * Studio module ID contains invalid characters.
     */
    #[NoDiscard]
    public static function invalidModuleId(string $moduleId): self
    {
        return new self(sprintf(
            'Invalid Studio module ID "%s": must match [a-z0-9_-]+',
            $moduleId,
        ));
    }

    /**
     * A module with the same ID is already registered.
     */
    #[NoDiscard]
    public static function duplicateModuleId(string $moduleId): self
    {
        return new self(sprintf(
            'Duplicate Studio module ID "%s": a module with this ID is already registered',
            $moduleId,
        ));
    }

    /**
     * Two modules declared the same route prefix.
     */
    #[NoDiscard]
    public static function routePrefixCollision(
        string $moduleId,
        string $routePrefix,
        string $existingModuleId,
    ): self {
        return new self(sprintf(
            'Studio module "%s" route prefix "%s" collides with existing module "%s"',
            $moduleId,
            $routePrefix,
            $existingModuleId,
        ));
    }
}
