<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for deploy check failures.
 *
 * Provides static factory methods for specific deploy error scenarios.
 */
#[Api(since: '1.0.0')]
final class DeployException extends RuntimeException
{
    /**
     * A specific check failed during execution.
     */
    #[NoDiscard]
    public static function checkFailed(string $name, string $reason): self
    {
        return new self(sprintf('Deploy check "%s" failed: %s', $name, $reason));
    }

    /**
     * The requested environment is not recognized.
     */
    #[NoDiscard]
    public static function invalidEnvironment(string $env): self
    {
        return new self(sprintf(
            'Invalid deployment environment "%s". Valid environments: local, staging, production',
            $env,
        ));
    }

    /**
     * Report generation encountered an unexpected error.
     */
    #[NoDiscard]
    public static function reportGenerationFailed(string $reason): self
    {
        return new self(sprintf('Deploy report generation failed: %s', $reason));
    }
}
