<?php

declare(strict_types=1);

namespace Pulsar\Container\Exception;

use Exception;
use NoDiscard;
use Psr\Container\ContainerExceptionInterface;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Exception thrown when the container encounters an error.
 * @api
 */
#[Api(since: '1.0.0')]
final class ContainerException extends Exception implements ContainerExceptionInterface
{
    /**
     * Create an exception for when a binding cannot be resolved.
     */
    #[NoDiscard]
    public static function unresolvable(string $id, string $reason = ''): self
    {
        $message = sprintf('Unable to resolve binding "%s"', $id);

        if ($reason !== '') {
            $message .= ': ' . $reason;
        }

        return new self($message);
    }

    /**
     * Create an exception for when a circular dependency is detected.
     *
     * @param list<string> $chain
     */
    #[NoDiscard]
    public static function circularDependency(string $id, array $chain): self
    {
        return new self(sprintf(
            'Circular dependency detected while resolving "%s": %s -> %s',
            $id,
            implode(' -> ', $chain),
            $id,
        ));
    }
}
