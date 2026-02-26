<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Guard\GuardInterface;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Stub auth manager for Tier A benchmarks.
 *
 * Returns a fixed authenticated identity for all requests.
 */
final class StubAuthManager implements AuthManagerInterface
{
    private readonly IdentityInterface $identity;

    public function __construct(?IdentityInterface $identity = null)
    {
        $this->identity = $identity ?? new Identity(
            id: 'bench-user-1',
            displayName: 'Benchmark User',
            roles: ['user', 'admin'],
        );
    }

    public function authenticate(ServerRequestInterface $request): IdentityInterface
    {
        return $this->identity;
    }

    public function guard(string $name): GuardInterface
    {
        return new StubGuard($this->identity);
    }

    public function defaultGuard(): string
    {
        return 'stub';
    }
}
