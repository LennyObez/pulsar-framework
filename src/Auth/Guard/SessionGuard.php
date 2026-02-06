<?php

declare(strict_types=1);

namespace Pulsar\Auth\Guard;

use Override;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Request;
use Pulsar\Security\Session\SessionInterface;

/**
 * Session-based authentication guard.
 *
 * Stores and retrieves identity data from the session.
 */
final class SessionGuard implements GuardInterface
{
    private const string SESSION_KEY = '_pulsar_identity';

    public function __construct(
        private readonly SessionInterface $session,
    ) {}

    #[Override]
    public function authenticate(Request $request): ?IdentityInterface
    {
        if (!$this->session->isStarted()) {
            return null;
        }

        if (!$this->session->has(self::SESSION_KEY)) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = $this->session->get(self::SESSION_KEY);

        if ($data === null) {
            return null;
        }

        return Identity::fromArray($data);
    }

    #[Override]
    public function name(): string
    {
        return 'session';
    }

    /**
     * Log an identity into the session.
     *
     * Regenerates the session ID to prevent session fixation.
     */
    public function login(IdentityInterface $identity): void
    {
        $this->session->regenerate();
        $this->storeIdentity($identity);
    }

    /**
     * Log out of the session.
     *
     * Removes the identity and regenerates the session ID.
     */
    public function logout(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->session->regenerate();
    }

    /**
     * Update the stored identity without regenerating the session ID.
     *
     * Useful for updating two-factor status after verification.
     */
    public function updateIdentity(IdentityInterface $identity): void
    {
        $this->storeIdentity($identity);
    }

    private function storeIdentity(IdentityInterface $identity): void
    {
        if ($identity instanceof Identity) {
            $this->session->set(self::SESSION_KEY, $identity->toArray());
        }
    }
}
