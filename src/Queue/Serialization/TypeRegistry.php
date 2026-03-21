<?php

declare(strict_types=1);

namespace Pulsar\Queue\Serialization;

use Pulsar\Api\Api;
use Pulsar\Queue\Exception\QueueException;

/**
 * Strict allowlist of job classes permitted for deserialization.
 *
 * Every class that may be deserialized from a queue payload must be
 * explicitly registered. Unknown classes are rejected to prevent
 * arbitrary object instantiation attacks.
 * @api
 */
#[Api(since: '1.0.0')]
final class TypeRegistry
{
    /** @var array<string, true> */
    private array $allowed = [];

    /**
     * Register a class as allowed for deserialization.
     */
    public function register(string $class): void
    {
        $this->allowed[$class] = true;
    }

    /**
     * Check whether a class is in the allowlist.
     */
    public function isAllowed(string $class): bool
    {
        return isset($this->allowed[$class]);
    }

    /**
     * Assert that a class is in the allowlist, throwing if not.
     *
     * @throws QueueException If the class is not registered.
     */
    public function assertAllowed(string $class): void
    {
        if (!$this->isAllowed($class)) {
            throw QueueException::typeNotAllowed($class);
        }
    }
}
