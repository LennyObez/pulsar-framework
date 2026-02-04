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
