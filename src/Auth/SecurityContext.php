<?php

declare(strict_types=1);

namespace Pulsar\Auth;

use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Request;

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
        private readonly Request $request,
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
     */
    public function isAuthenticated(): bool
    {
        return $this->identity()->isAuthenticated();
    }
}
