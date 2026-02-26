<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Handler;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\Exception\SecurityException;

/**
 * In-memory array session handler for testing.
 *
 * Stores all session data in a PHP array that does not persist between requests.
 */
#[Internal]
final class ArrayHandler implements SessionHandlerInterface
{
    /** @var array<string, string> */
    private array $sessions = [];

    #[Override]
    public function open(string $path, string $name): bool
    {
        return true;
    }

    #[Override]
    public function close(): bool
    {
        return true;
    }

    #[Override]
    public function read(string $id): string
    {
        return $this->sessions[$id] ?? '';
    }

    #[Override]
    public function write(string $id, string $data): bool
    {
        $this->sessions[$id] = $data;

        return true;
    }

    #[Override]
    public function destroy(string $id): bool
    {
        unset($this->sessions[$id]);

        return true;
    }

    #[Override]
    public function gc(int $max_lifetime): int
    {
        return 0;
    }

    #[Override]
    public function supportsConcurrencyControl(): bool
    {
        return false;
    }

    #[Override]
    public function supportsSessionListing(): bool
    {
        return false;
    }

    #[Override]
    public function supportsRevocation(): bool
    {
        return false;
    }

    #[Override]
    public function listSessions(string $userId): array
    {
        throw SecurityException::sessionHandlerNotSupported('session listing', 'array');
    }

    #[Override]
    public function revokeSession(string $sessionId): bool
    {
        throw SecurityException::sessionHandlerNotSupported('session revocation', 'array');
    }

    #[Override]
    public function getActiveSessions(string $userId): int
    {
        throw SecurityException::sessionHandlerNotSupported('concurrency control', 'array');
    }
}
