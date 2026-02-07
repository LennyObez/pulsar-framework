<?php

declare(strict_types=1);

namespace Pulsar\Build;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function count;
use function implode;
use function sprintf;

/**
 * Exception thrown during build pipeline operations.
 */
#[Api(since: '1.0.0')]
final class BuildException extends RuntimeException
{
    #[NoDiscard]
    public static function artifactWriteFailed(string $path, string $reason): self
    {
        return new self(sprintf('Failed to write artifact "%s": %s', $path, $reason));
    }

    #[NoDiscard]
    public static function staleBuild(string $artifact, string $reason): self
    {
        return new self(sprintf('Build artifact "%s" is stale: %s', $artifact, $reason));
    }

    #[NoDiscard]
    public static function integrityCheckFailed(string $artifact): self
    {
        return new self(sprintf(
            'Integrity check failed for artifact "%s". '
            . 'The artifact may have been tampered with or corrupted. Run `pulsar build` to rebuild.',
            $artifact,
        ));
    }

    /**
     * @param list<string> $artifacts
     */
    #[NoDiscard]
    public static function integrityCheckFailedMultiple(array $artifacts): self
    {
        return new self(sprintf(
            'Integrity check failed for %d artifact(s): %s. '
            . 'Run `pulsar build` to rebuild.',
            count($artifacts),
            implode(', ', $artifacts),
        ));
    }

    #[NoDiscard]
    public static function missingArtifact(string $artifact): self
    {
        return new self(sprintf(
            'Required build artifact "%s" is missing. Run `pulsar build` before deploying to production.',
            $artifact,
        ));
    }

    /**
     * @param list<string> $artifacts
     */
    #[NoDiscard]
    public static function missingArtifacts(array $artifacts): self
    {
        return new self(sprintf(
            'Required build artifacts are missing: %s. Run `pulsar build` before deploying to production.',
            implode(', ', $artifacts),
        ));
    }

    #[NoDiscard]
    public static function compilationFailed(string $step, string $reason): self
    {
        return new self(sprintf('Build step "%s" failed: %s', $step, $reason));
    }

    #[NoDiscard]
    public static function signatureVerificationFailed(): self
    {
        return new self(
            'Build manifest signature verification failed. '
            . 'The manifest may have been tampered with. Run `pulsar build --sign` to re-sign.',
        );
    }

    #[NoDiscard]
    public static function atomicWriteFailed(string $tmpPath, string $finalPath): self
    {
        return new self(sprintf(
            'Atomic rename from "%s" to "%s" failed. Check filesystem permissions.',
            $tmpPath,
            $finalPath,
        ));
    }
}
