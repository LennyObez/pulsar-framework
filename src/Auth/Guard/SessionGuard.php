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

use function array_keys;
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
     * Discards every session key, not only the identity. `regenerate()`
     * carries the data array over to the new id, so dropping the identity
     * alone leaves the rest of the session — OAuth state, wizard progress,
     * flash, CSRF token — readable by whoever authenticates next in the same
     * browser. `SessionInterface` has no flush primitive, so the guard clears
     * the keys it can enumerate and then rotates: `regenerate()` destroys the
     * old server-side record (ASVS V3.3.1) and mints a fresh id, while keeping
     * the request-binding metadata the session validators compare against.
     *
     * The identity is dropped explicitly before the sweep rather than relying
     * on it turning up in `all()`. `SessionInterface` is public API and its
     * implementations are not all this repository's; one whose `all()` under-
     * reports would otherwise leave the session authenticated, which is a
     * strictly worse failure than leaking a residual key.
     */
    public function logout(): void
    {
        $this->session->remove(self::SESSION_KEY);

        foreach (array_keys($this->session->all()) as $key) {
            $this->session->remove($key);
        }

        $this->session->regenerate();
    }

    /**
     * Update the stored identity, regenerating the session ID on
     * privilege escalation.
     *
     * A 2FA verification flips the identity's `twoFactorStatus` from
     * Pending to Verified — that is a privilege change, and OWASP
     * ASVS V3.5.3 / PSD2 SCA mandate a session-id rotation at that
     * boundary so a session-fixation attempt cannot ride along with
     * the elevated state. The guard compares the previous and new
     * identities; when the new one is more privileged (TwoFactorStatus
     * advanced from Pending / Disabled to Verified, OR roles strictly
     * grew), it issues `regenerate(true)` before storing the identity.
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
     * A privilege escalation triggers a session-id rotation.
     * Two events count as escalation:
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
     * Refuses to silently drop a custom `IdentityInterface`
     * implementation. Skipping storage for an identity this guard
     * cannot serialise would let `login()` return successfully while
     * leaving the session empty — the auth flow would appear to work
     * and the next request would find no logged-in user. Failing loudly
     * here is the only way the caller learns it needs its own guard.
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
