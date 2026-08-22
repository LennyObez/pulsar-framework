<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Contract for session management.
 *
 * Abstracts session operations for testability while allowing
 * the concrete implementation to remain final.
 * @api
 */
#[Api(since: '1.0.0')]
interface SessionInterface
{
    /**
     * Start the session.
     */
    public function start(): void;

    /**
     * Check if the session is active.
     */
    public function isStarted(): bool;

    /**
     * Get a value from the session.
     */
    #[NoDiscard]
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Get a value from the session as a string. Returns the default when the
     * key is missing or the value is not a string.
     */
    #[NoDiscard]
    public function getString(string $key, string $default = ''): string;

    /**
     * Get a value from the session as a nullable string. Returns null when the
     * key is missing or the value is not a string.
     */
    #[NoDiscard]
    public function getNullableString(string $key): ?string;

    /**
     * Get a value from the session as an int. Returns the default when the key
     * is missing or the value is not int/numeric-string.
     */
    #[NoDiscard]
    public function getInt(string $key, int $default = 0): int;

    /**
     * Get a value from the session as a bool. Returns the default when the key
     * is missing or the value is not a bool.
     */
    #[NoDiscard]
    public function getBool(string $key, bool $default = false): bool;

    /**
     * Set a value in the session.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check if a key exists in the session.
     */
    public function has(string $key): bool;

    /**
     * Remove a key from the session.
     */
    public function remove(string $key): void;

    /**
     * Get the current session ID.
     */
    public function id(): string;

    /**
     * Regenerate the session ID.
     */
    public function regenerate(bool $deleteOldSession = true): void;

    /**
     * Destroy the session completely.
     */
    public function destroy(): void;

    /**
     * Get all session data.
     *
     * @return array<string, mixed>
     */
    public function all(): array;
}
