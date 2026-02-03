<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for feature flag errors.
 */
#[Api]
final class FeatureFlagException extends RuntimeException
{
    /**
     * Storage operation failed.
     */
    public static function storageError(string $reason): self
    {
        return new self(sprintf('Feature flag storage error: %s', $reason));
    }

    /**
     * Flag definition is invalid.
     */
    public static function invalidDefinition(string $flagName, string $reason): self
    {
        return new self(sprintf('Invalid feature flag definition "%s": %s', $flagName, $reason));
    }
}
