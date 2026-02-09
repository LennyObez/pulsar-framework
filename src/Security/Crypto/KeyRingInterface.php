<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;

/**
 * Key ring for resolving cryptographic keys by their identifier.
 *
 * Supports key rotation by maintaining multiple keys indexed by kid.
 */
#[Api]
interface KeyRingInterface
{
    /**
     * Resolve a key by its identifier.
     *
     * @return string|null Raw key bytes, or null if kid is unknown
     */
    public function keyFor(string $kid): ?string;

    /**
     * Return all keys in the ring (excludes internal aliases).
     *
     * @return iterable<string, string> kid => raw key bytes
     */
    public function all(): iterable;
}
