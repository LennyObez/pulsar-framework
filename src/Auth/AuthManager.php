<?php

declare(strict_types=1);

namespace Pulsar\Auth;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Guard\GuardInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Runtime\ResettableInterface;

use function array_key_exists;

/**
 * Manages authentication guards and resolves identities from requests.
 *
 * Iterates registered guards in priority order. Falls back to AnonymousIdentity
 * if no guard can authenticate the request.
 */
final class AuthManager implements AuthManagerInterface, ResettableInterface
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

    #[Override]
    public function authenticate(ServerRequestInterface $request): IdentityInterface
    {
        foreach ($this->priority as $name) {
            $identity = $this->guards[$name]->authenticate($request);

            if ($identity !== null) {
                return $identity;
            }
        }

        return new AnonymousIdentity();
    }

    #[Override]
    public function guard(string $name): GuardInterface
    {
        if (!array_key_exists($name, $this->guards)) {
            throw AuthenticationException::unknownGuard($name);
        }

        return $this->guards[$name];
    }

    #[Override]
    public function defaultGuard(): string
    {
        return $this->defaultGuardName;
    }

    /**
     * Reset request-scoped state (defense-in-depth).
     *
     * Currently a no-op since AuthManager delegates identity resolution
     * to guards and SecurityContext. Protects against future internal
     * caching additions leaking state between requests.
     */
    #[Override]
    public function resetRequestState(): void
    {
        // Defense-in-depth: no internal caches currently exist,
        // but if any are added in the future, clear them here.
    }
}
