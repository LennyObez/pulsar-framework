<?php

declare(strict_types=1);

namespace Pulsar\Auth;

use function array_key_exists;

use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Guard\GuardInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Request;

/**
 * Manages authentication guards and resolves identities from requests.
 *
 * Iterates registered guards in priority order. Falls back to AnonymousIdentity
 * if no guard can authenticate the request.
 */
final class AuthManager implements AuthManagerInterface
{
    /** @var array<string, GuardInterface> */
    private array $guards = [];

    /** @var list<string> Guard names in priority order */
    private array $priority = [];

    public function __construct(
        private readonly string $defaultGuardName = 'session',
    ) {}

    /**
     * Register a guard with optional priority ordering.
     */
    public function addGuard(GuardInterface $guard): void
    {
        $this->guards[$guard->name()] = $guard;
        $this->priority[] = $guard->name();
    }

    public function authenticate(Request $request): IdentityInterface
    {
        foreach ($this->priority as $name) {
            $identity = $this->guards[$name]->authenticate($request);

            if ($identity !== null) {
                return $identity;
            }
        }

        return new AnonymousIdentity();
    }

    public function guard(string $name): GuardInterface
    {
        if (!array_key_exists($name, $this->guards)) {
            throw AuthenticationException::unknownGuard($name);
        }

        return $this->guards[$name];
    }

    public function defaultGuard(): string
    {
        return $this->defaultGuardName;
    }
}
