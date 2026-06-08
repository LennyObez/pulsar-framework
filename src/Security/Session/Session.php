<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use NoDiscard;
use Override;
use Pulsar\Config\SessionConfig;
use Pulsar\Security\Exception\SecurityException;

use function array_key_exists;
use function ini_get;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function session_destroy;
use function session_get_cookie_params;
use function session_id;
use function session_name;
use function session_regenerate_id;
use function session_start;
use function session_status;
use function setcookie;

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
    #[Override]
    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        session_name($this->config->effectiveCookieName());

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

    #[Override]
    public function isStarted(): bool
    {
        return $this->started || session_status() === PHP_SESSION_ACTIVE;
    }

    #[Override]
    #[NoDiscard]
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        return $_SESSION[$key] ?? $default;
    }

    #[Override]
    #[NoDiscard]
    public function getString(string $key, string $default = ''): string
    {
        $this->ensureStarted();

        /** @var mixed $value */
        $value = $_SESSION[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    #[Override]
    #[NoDiscard]
    public function getNullableString(string $key): ?string
    {
        $this->ensureStarted();

        /** @var mixed $value */
        $value = $_SESSION[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    #[Override]
    #[NoDiscard]
    public function getInt(string $key, int $default = 0): int
    {
        $this->ensureStarted();

        /** @var mixed $value */
        $value = $_SESSION[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }

    #[Override]
    #[NoDiscard]
    public function getBool(string $key, bool $default = false): bool
    {
        $this->ensureStarted();

        /** @var mixed $value */
        $value = $_SESSION[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    #[Override]
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();

        $_SESSION = [...$_SESSION, $key => $value];
    }

    #[Override]
    public function has(string $key): bool
    {
        $this->ensureStarted();

        /** @psalm-suppress InvalidScalarArgument: $_SESSION is always available after session_start() */
        return array_key_exists($key, $_SESSION);
    }

    #[Override]
    public function remove(string $key): void
    {
        $this->ensureStarted();

        unset($_SESSION[$key]);
    }

    #[Override]
    public function id(): string
    {
        return session_id() ?: '';
    }

    /**
     * Regenerate the session ID (preserving session data).
     *
     * Use after privilege escalation (login, role change) to prevent session fixation.
     */
    #[Override]
    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->ensureStarted();

        // SEC-HTTP-02: session_regenerate_id() returns false when the session
        // handler refuses the rotation (permissions on the session save_path,
        // a redis backend that lost its connection, etc). The previous code
        // ignored the failure and let the caller continue with the OLD ID —
        // exactly the fixation vector that regenerate() exists to close. Now
        // we destroy the session and raise so the caller cannot mistake a
        // failed rotation for a successful one.
        if (!@session_regenerate_id($deleteOldSession)) {
            $this->destroy();

            throw new SecurityException(
                'session_regenerate_id() failed; session destroyed to close the fixation window.',
            );
        }
    }

    #[Override]
    public function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                $name = session_name();
                if ($name !== false) {
                    setcookie($name, '', [
                        'expires' => 1,
                        'path' => $params['path'],
                        'domain' => $params['domain'],
                        'secure' => $params['secure'],
                        'httponly' => $params['httponly'],
                        'samesite' => $params['samesite'],
                    ]);
                }
            }

            session_destroy();
        }

        $this->started = false;
    }

    /**
     * Get all session data.
     *
     * @return array<string, mixed>
     *
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement: $_SESSION keys are strings after session_start()
     */
    #[Override]
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
