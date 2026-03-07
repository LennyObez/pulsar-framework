<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use Override;
use Pulsar\Config\CsrfConfig;
use Pulsar\Security\Session\SessionInterface;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function bin2hex;
use function hash_equals;
use function is_string;

/**
 * CSRF token manager using the synchronizer token pattern.
 *
 * Tokens are stored server-side in the session. On state-changing requests,
 * the submitted token is compared against the stored token using constant-time
 * comparison.
 */
final class CsrfTokenManager implements CsrfTokenManagerInterface
{
    /**
     * Session key for storing the CSRF token.
     */
    private const string SESSION_KEY = '_csrf_token';

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly CsrfConfig $config,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Generate a new CSRF token and store it in the session.
     *
     * @return string The generated token (hex-encoded)
     *
     * @throws RandomException
     */
    #[Override]
    public function generate(): string
    {
        $token = bin2hex($this->randomizer->getBytes(max(1, $this->config->tokenLength)));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Get the current CSRF token, generating one if none exists.
     *
     * @throws RandomException
     */
    #[Override]
    public function getToken(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token)) {
            return $this->generate();
        }

        return $token;
    }

    /**
     * Validate a submitted token against the stored session token.
     *
     * Uses constant-time comparison to prevent timing attacks.
     */
    #[Override]
    public function validate(string $submittedToken): bool
    {
        $storedToken = $this->session->get(self::SESSION_KEY);

        if (!is_string($storedToken)) {
            return false;
        }

        return hash_equals($storedToken, $submittedToken);
    }

    /**
     * Rotate the CSRF token (generate a new one, invalidating the old)
     * AND regenerate the underlying session ID.
     *
     * F9.6: rotating only the CSRF token without regenerating the session
     * ID is a half measure. The canonical anti-fixation flow on a state
     * boundary (post-login, privilege change, password reset) requires
     * `Session::regenerate(true)` so any session ID an attacker may have
     * fixated is destroyed alongside the old token. Skipping the session
     * regeneration left a viable session-fixation window.
     *
     * Order matters: regenerate the session first so the new token is
     * stored under the new session id; otherwise the freshly stored
     * token would be tied to the old (potentially compromised) session
     * before the regenerate call swept it.
     *
     * @throws RandomException
     */
    #[Override]
    public function rotate(): string
    {
        $this->session->regenerate(true);

        return $this->generate();
    }
}
