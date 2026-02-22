<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Guard\GuardInterface;
use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Stub guard for Tier A benchmarks.
 */
final class StubGuard implements GuardInterface
{
    public function __construct(
        private readonly IdentityInterface $identity,
    ) {}

    public function authenticate(ServerRequestInterface $request): IdentityInterface
    {
        return $this->identity;
    }

    public function name(): string
    {
        return 'stub';
    }
}
