<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use function array_key_exists;

use Pulsar\Config\SessionConfig;
use Pulsar\Security\Exception\SecurityException;

use function session_destroy;
use function session_id;
use function session_name;
use function session_regenerate_id;
use function session_start;
use function session_status;

/**
 * Secure session wrapper around PHP's native session functions.
 *
 * Applies security settings from SessionConfig (HttpOnly, Secure, SameSite)
 * and provides a testable abstraction over `$_SESSION`.
 */
final class Session implements SessionInterface
{
    private bool $started = false;

    public function __construct(
        private readonly SessionConfig $config,
    ) {}

    /**
     * Start the session with security settings applied.
     *
     * @throws SecurityException If the session cannot be started
     */
    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        session_name($this->config->cookieName);

        $started = session_start([
            'cookie_httponly' => $this->config->cookieHttpOnly,
            'cookie_secure' => $this->config->cookieSecure,
            'cookie_samesite' => $this->config->cookieSameSite,
            'cookie_lifetime' => $this->config->lifetime,
            'use_strict_mode' => true,
            'use_only_cookies' => true,
        ]);

        if (!$started) {
            throw SecurityException::sessionStartFailed();
        }

        $this->started = true;
    }

    /**
     * Check if the session is active.
     */
    public function isStarted(): bool
    {
        return $this->started || session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Get a value from the session.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a value in the session.
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();

        $_SESSION[$key] = $value;
    }

    /**
     * Check if a key exists in the session.
     */
    public function has(string $key): bool
    {
        $this->ensureStarted();

        /** @psalm-suppress InvalidScalarArgument -- $_SESSION is always available after session_start() */
        return array_key_exists($key, $_SESSION);
    }

    /**
     * Remove a key from the session.
     */
    public function remove(string $key): void
    {
        $this->ensureStarted();

        unset($_SESSION[$key]);
    }

    /**
     * Get the current session ID.
     */
    public function id(): string
    {
        return session_id() ?: '';
    }

    /**
     * Regenerate the session ID (preserving session data).
     *
     * Use after privilege escalation (login, role change) to prevent session fixation.
     */
    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->ensureStarted();

        session_regenerate_id($deleteOldSession);
    }

    /**
     * Destroy the session completely.
     */
    public function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        $this->started = false;
    }

    /**
     * Get all session data.
     *
     * @return array<string, mixed>
     *
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement -- $_SESSION keys are strings after session_start()
     */
    public function all(): array
    {
        $this->ensureStarted();

        /** @var array<string, mixed> $_SESSION */
        return $_SESSION;
    }

    /**
     * Ensure the session is started before reading/writing.
     *
     * @throws SecurityException If the session has not been started
     */
    private function ensureStarted(): void
    {
        if (!$this->isStarted()) {
            throw SecurityException::sessionNotStarted();
        }
    }
}
