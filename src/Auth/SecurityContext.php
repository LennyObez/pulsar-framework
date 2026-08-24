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
 *
 * One instance per request, published by
 * {@see \Pulsar\Auth\Middleware\AuthenticationMiddleware} into the
 * {@see AuthenticationState} holder. Everything that needs the caller shares
 * that one instance, so the guards run at most once per request however many
 * frames ask.
 */
final class SecurityContext
{
    private ?IdentityInterface $resolved = null;

    public function __construct(
        private readonly AuthManagerInterface $authManager,
        private readonly ServerRequestInterface $request,
    ) {}

    /**
     * A context whose answer was decided before the guards could run.
     *
     * One caller: `pulsar serve --dev-identity`, which knows who the developer
     * is without any credential in the request. That identity used to travel as
     * the `identity` REQUEST ATTRIBUTE, read back by
     * {@see \Pulsar\Auth\Middleware\AuthenticationMiddleware} — which made an
     * attribute an INPUT to authentication, so any globally piped frame ahead of
     * it could name the caller and be believed. Seeding the context instead
     * keeps the injection point where the framework can see it, and makes the
     * dev identity the same object every frame reads rather than one the `auth`
     * alias would overwrite with a guard's answer.
     *
     * The manager and request are still taken, because the context is otherwise
     * indistinguishable from a lazy one: nothing downstream has to know which
     * kind it holds, and a future re-resolution has what it needs.
     */
    public static function established(
        AuthManagerInterface $authManager,
        ServerRequestInterface $request,
        IdentityInterface $identity,
    ): self {
        $context = new self($authManager, $request);
        $context->resolved = $identity;

        return $context;
    }

    /**
     * Get the current identity, resolving lazily on first access.
     */
    public function identity(): IdentityInterface
    {
        return $this->resolved ??= $this->authManager->authenticate($this->request);
    }

    /**
     * The identity if it is already known, without asking the guards.
     *
     * The lazy half of this class only pays off if reading it is optional, so
     * this exists for the frames that want to REPORT the caller without
     * deciding to authenticate one: {@see \Pulsar\Auth\Middleware\AuthenticationMiddleware}
     * fills the `identity` request attribute from it and leaves the guards
     * unconsulted on every route that never asks who is calling.
     */
    public function tryIdentity(): ?IdentityInterface
    {
        return $this->resolved;
    }

    /**
     * Check if the current identity is authenticated.
     */
    public function isAuthenticated(): bool
    {
        return $this->identity()->isAuthenticated();
    }
}
