<?php

declare(strict_types=1);

namespace Pulsar\Auth\Guard;

use LogicException;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Security\Session\SessionInterface;

use function count;
use function sprintf;

/**
 * Session-based authentication guard.
 *
 * Stores and retrieves identity data from the session.
 * @api
 */
#[Api(since: '1.0.0')]
final class SessionGuard implements GuardInterface
{
    private const string SESSION_KEY = '_pulsar_identity';

    public function __construct(
        private readonly SessionInterface $session,
    ) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?IdentityInterface
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
     * Update the stored identity, regenerating the session ID on
     * privilege escalation.
     *
     * F12.15: a 2FA verification flips the identity's
     * `twoFactorStatus` from Pending to Verified — that's a
     * privilege change, and OWASP ASVS V3.5.3 / PSD2 SCA mandate
     * a session-id rotation at that boundary so any session-fixation
     * attempt cannot ride along with the elevated state. The guard
     * compares the previous and new identities; when the new one
     * is more privileged (TwoFactorStatus advanced from Pending /
     * Disabled to Verified, OR roles strictly grew), it issues
     * `regenerate(true)` before storing the new identity.
     */
    public function updateIdentity(IdentityInterface $identity): void
    {
        $previous = $this->session->isStarted() && $this->session->has(self::SESSION_KEY)
            ? $this->authenticate(new \Pulsar\Http\Message\ServerRequest())
            : null;

        if ($previous !== null && self::isPrivilegeEscalation($previous, $identity)) {
            $this->session->regenerate(true);
        }

        $this->storeIdentity($identity);
    }

    /**
     * F12.15: a privilege escalation triggers a session-id rotation.
     * We currently treat two events as escalation:
     *   - twoFactorStatus advances from Disabled / Pending to Verified
     *   - the role set strictly grows (every previous role still
     *     present + at least one new role)
     */
    private static function isPrivilegeEscalation(
        IdentityInterface $previous,
        IdentityInterface $next,
    ): bool {
        if (!$previous instanceof Identity || !$next instanceof Identity) {
            // Custom Identity implementations: be conservative and
            // rotate on every update to avoid silently missing an
            // escalation we don't know how to detect.
            return true;
        }

        $previousStatus = $previous->twoFactorStatus();
        $nextStatus = $next->twoFactorStatus();

        if ($previousStatus !== $nextStatus
            && $nextStatus === \Pulsar\Auth\Identity\TwoFactorStatus::Verified
        ) {
            return true;
        }

        $previousRoles = $previous->roles();
        $nextRoles = $next->roles();

        if (count($nextRoles) > count($previousRoles)) {
            return true;
        }

        return false;
    }

    /**
     * F12.9: refuses to silently drop a custom `IdentityInterface`
     * implementation. The previous `if ($identity instanceof Identity)`
     * check would let `login()` succeed with a domain-specific
     * identity object while leaving the session empty — a confusing
     * race where the auth flow appeared to work but the next request
     * found no logged-in user.
     */
    private function storeIdentity(IdentityInterface $identity): void
    {
        if (!$identity instanceof Identity) {
            throw new LogicException(sprintf(
                'SessionGuard::storeIdentity expected %s, got %s. Custom '
                . 'IdentityInterface implementations need a guard that knows '
                . 'how to serialise them — wire your own guard or extend '
                . 'Identity to inherit toArray().',
                Identity::class,
                $identity::class,
            ));
        }

        $this->session->set(self::SESSION_KEY, $identity->toArray());
    }
}
