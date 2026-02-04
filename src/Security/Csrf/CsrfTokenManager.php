<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use function bin2hex;
use function hash_equals;
use function is_string;

use Pulsar\Config\CsrfConfig;
use Pulsar\Security\Session\SessionInterface;
use Random\RandomException;

use function random_bytes;

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

    public function __construct(
        private readonly SessionInterface $session,
        private readonly CsrfConfig $config,
    ) {}

    /**
     * Generate a new CSRF token and store it in the session.
     *
     * @return string The generated token (hex-encoded)
     *
     * @throws RandomException
     */
    public function generate(): string
    {
        $token = bin2hex(random_bytes(max(1, $this->config->tokenLength)));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Get the current CSRF token, generating one if none exists.
     *
     * @throws RandomException
     */
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
    public function validate(string $submittedToken): bool
    {
        $storedToken = $this->session->get(self::SESSION_KEY);

        if (!is_string($storedToken)) {
            return false;
        }

        return hash_equals($storedToken, $submittedToken);
    }

    /**
     * Rotate the CSRF token (generate a new one, invalidating the old).
     *
     * Call after successful form submission to prevent replay.
     *
     * @throws RandomException
     */
    public function rotate(): string
    {
        return $this->generate();
    }
}
