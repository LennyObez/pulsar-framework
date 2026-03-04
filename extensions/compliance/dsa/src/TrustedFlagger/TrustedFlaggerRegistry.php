<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\TrustedFlagger;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Registry for trusted flaggers per DSA Article 22.
 *
 * Trusted flaggers are entities designated by Digital Services Coordinators
 * that have particular expertise in detecting, identifying, and notifying
 * illegal content. Their submissions receive priority processing.
 */
#[Api(since: '1.0.0')]
abstract class TrustedFlaggerRegistry
{
    /**
     * Register a trusted flagger.
     */
    abstract public function register(
        string $id,
        string $name,
        string $designatingAuthority,
        string $expertiseDomain,
    ): void;

    /**
     * Remove a trusted flagger from the registry.
     */
    abstract public function revoke(string $id): void;

    /**
     * Check whether a flagger ID belongs to a registered trusted flagger.
     */
    #[NoDiscard]
    abstract public function isTrusted(string $id): bool;

    /**
     * Retrieve all registered trusted flaggers.
     *
     * @return list<array{id: string, name: string, designating_authority: string, expertise_domain: string}>
     */
    #[NoDiscard]
    abstract public function all(): array;

    /**
     * Return the total number of registered trusted flaggers.
     */
    #[NoDiscard]
    abstract public function count(): int;
}
