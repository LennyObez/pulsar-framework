<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Stub token resolver for Tier A benchmarks.
 *
 * Returns a fixed authenticated identity for any token.
 */
final class StubTokenResolver
{
    private readonly IdentityInterface $identity;

    public function __construct(?IdentityInterface $identity = null)
    {
        $this->identity = $identity ?? new Identity(
            id: 'bench-user-token',
            displayName: 'Benchmark Token User',
            roles: ['user'],
        );
    }

    public function resolve(string $token): IdentityInterface
    {
        return $this->identity;
    }
}
