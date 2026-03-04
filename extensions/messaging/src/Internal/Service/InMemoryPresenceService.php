<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Extension\Messaging\Contracts\PresenceServiceInterface;

use function array_keys;
use function array_values;

/**
 * In-memory presence tracking for single-server deployments.
 *
 * Tracks online status in a PHP array. Suitable for single-process
 * or single-server deployments. For multi-server, swap in a
 * Redis-backed implementation via the container.
 */
#[Internal(reason: 'Use PresenceServiceInterface for public API')]
final class InMemoryPresenceService implements PresenceServiceInterface
{
    /** @var array<string, true> User ID => online flag */
    private array $onlineUsers = [];

    public function setOnline(string $userId): void
    {
        $this->onlineUsers[$userId] = true;
    }

    public function setOffline(string $userId): void
    {
        unset($this->onlineUsers[$userId]);
    }

    public function isOnline(string $userId): bool
    {
        return isset($this->onlineUsers[$userId]);
    }

    public function getOnlineUsers(): array
    {
        return array_values(array_keys($this->onlineUsers));
    }
}
