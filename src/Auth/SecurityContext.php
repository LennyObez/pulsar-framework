<?php

declare(strict_types=1);

namespace Pulsar\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Lazy identity resolver.
 *
 * Wraps the AuthManager and Request. The identity is only resolved
 * on the first call to identity(), avoiding the cost of full
 * authentication on public routes that never inspect identity.
 */
final class SecurityContext
{
    private ?IdentityInterface $resolved = null;

    public function __construct(
        private readonly AuthManagerInterface $authManager,
        private readonly ServerRequestInterface $request,
    ) {}

    /**
     * Get the current identity, resolving lazily on first access.
     */
    public function identity(): IdentityInterface
    {
        return $this->resolved ??= $this->authManager->authenticate($this->request);
    }

    /**
     * Check if the current identity is authenticated.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function isAuthenticated(): bool
    {
        return $this->identity()->isAuthenticated();
    }
}
