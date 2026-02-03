<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Exception;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Exception for extension dependency resolution errors.
 */
#[Api]
final class DependencyException extends ExtensionException
{
    /**
     * Create exception for missing dependency.
     */
    #[NoDiscard]
    public static function missingDependency(string $extension, string $dependency): self
    {
        return new self(sprintf(
            'Extension "%s" requires extension "%s" which is not available',
            $extension,
            $dependency,
        ));
    }

    /**
     * Create exception for dependency version mismatch.
     */
    #[NoDiscard]
    public static function versionMismatch(
        string $extension,
        string $dependency,
        string $required,
        string $available,
    ): self {
        return new self(sprintf(
            'Extension "%s" requires "%s" version %s, but version %s is available',
            $extension,
            $dependency,
            $required,
            $available,
        ));
    }

    /**
     * Create exception for circular dependency.
     *
     * @param list<string> $chain
     */
    #[NoDiscard]
    public static function circularDependency(array $chain): self
    {
        return new self(sprintf(
            'Circular dependency detected: %s',
            implode(' -> ', $chain),
        ));
    }

    /**
     * Create exception for unresolvable dependency graph.
     */
    #[NoDiscard]
    public static function unresolvable(string $reason): self
    {
        return new self(sprintf('Unable to resolve extension dependencies: %s', $reason));
    }
}
