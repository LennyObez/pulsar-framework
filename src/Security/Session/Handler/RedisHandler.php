<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Handler;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\Exception\SecurityException;
use Redis;
use RedisException;

use function json_decode;
use function json_encode;
use function time;

/**
 * Redis-backed session handler with atomic concurrency control.
 *
 * Regulated-recommended handler (alongside Database) supporting:
 * - Concurrent session limits via atomic Lua scripts
 * - Session listing and revocation per user
 * - TTL-based garbage collection
 * - Horizontal scaling
 *
 * Data model:
 * - Session data: `{prefix}session:{id}`: stores JSON with data + metadata
 * - User sessions: `{prefix}user:{userId}`: Redis Set of active session IDs
 */
#[Internal]
final class RedisHandler implements SessionHandlerInterface
{
    private const string LUA_CONCURRENCY_CHECK = <<<'LUA'
        local count = redis.call('SCARD', KEYS[1])
        if count >= tonumber(ARGV[1]) then
            return 0
        end
        redis.call('SADD', KEYS[1], ARGV[2])
        return 1
        LUA;

    private ?string $currentUserId = null;

    private string $currentIpAddress = '';

    private string $currentUserAgent = '';

    public function __construct(
        private readonly Redis $redis,
        private readonly int $lifetime = 7200,
        private readonly string $prefix = 'pulsar:session:',
    ) {}

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
        try {
            $key = $this->sessionKey($id);
            /** @var string|false $raw */
            $raw = $this->redis->get($key);

            if ($raw === false) {
                return '';
            }

            /** @var array{data?: string}|null $decoded */
            $decoded = json_decode($raw, true);

            if ($decoded === null) {
                return '';
            }

            return $decoded['data'] ?? '';
        } catch (RedisException) {
            return '';
        }
    }

    #[Override]
    public function write(string $id, string $data): bool
    {
        try {
            $key = $this->sessionKey($id);
            /** @var string|false $existing */
            $existing = $this->redis->get($key);

            $now = time();
            $createdAt = $now;

            if ($existing !== false) {
                /** @var array{created_at?: int}|null $existingDecoded */
                $existingDecoded = json_decode($existing, true);
                $createdAt = $existingDecoded['created_at'] ?? $now;
            }

            $payload = json_encode([
                'data' => $data,
                'user_id' => $this->currentUserId,
                'ip_address' => $this->currentIpAddress,
                'user_agent' => $this->currentUserAgent,
                'last_activity' => $now,
                'created_at' => $createdAt,
            ], JSON_THROW_ON_ERROR);

            $this->redis->setex($key, $this->lifetime, $payload);

            if ($this->currentUserId !== null && $this->currentUserId !== '') {
                $userKey = $this->userSessionsKey($this->currentUserId);
                $this->redis->sAdd($userKey, $id);
                $this->redis->expire($userKey, $this->lifetime * 2);
            }

            return true;
        } catch (RedisException) {
            return false;
        }
    }

    #[Override]
    public function destroy(string $id): bool
    {
        try {
            $key = $this->sessionKey($id);
            /** @var string|false $raw */
            $raw = $this->redis->get($key);

            if ($raw !== false) {
                /** @var array{user_id?: string|null}|null $decoded */
                $decoded = json_decode($raw, true);

                if ($decoded !== null && isset($decoded['user_id']) && $decoded['user_id'] !== '') {
                    $userKey = $this->userSessionsKey($decoded['user_id']);
                    $this->redis->sRem($userKey, $id);
                }
            }

            $this->redis->del($key);

            return true;
        } catch (RedisException) {
            return false;
        }
    }

    #[Override]
    public function gc(int $max_lifetime): int
    {
        // Redis handles expiry via TTL: no manual GC needed.
        // Clean up stale entries in user session sets.
        return 0;
    }

    #[Override]
    public function supportsConcurrencyControl(): bool
    {
        return true;
    }

    #[Override]
    public function supportsSessionListing(): bool
    {
        return true;
    }

    #[Override]
    public function supportsRevocation(): bool
    {
        return true;
    }

    /**
     * @return list<array{id: string, last_activity: int, ip_address: string, user_agent: string, created_at: int}>
     */
    #[Override]
    public function listSessions(string $userId): array
    {
        try {
            $userKey = $this->userSessionsKey($userId);
            /** @var list<string> $sessionIds */
            $sessionIds = $this->redis->sMembers($userKey);
            $sessions = [];
            $expiredIds = [];

            foreach ($sessionIds as $sessionId) {
                $key = $this->sessionKey($sessionId);
                /** @var string|false $raw */
                $raw = $this->redis->get($key);

                if ($raw === false) {
                    $expiredIds[] = $sessionId;

                    continue;
                }

                /** @var array{last_activity?: int, ip_address?: string, user_agent?: string, created_at?: int}|null $decoded */
                $decoded = json_decode($raw, true);

                if ($decoded === null) {
                    $expiredIds[] = $sessionId;

                    continue;
                }

                $sessions[] = [
                    'id' => $sessionId,
                    'last_activity' => $decoded['last_activity'] ?? 0,
                    'ip_address' => $decoded['ip_address'] ?? '',
                    'user_agent' => $decoded['user_agent'] ?? '',
                    'created_at' => $decoded['created_at'] ?? 0,
                ];
            }

            // Clean up references to expired sessions
            foreach ($expiredIds as $expiredId) {
                $this->redis->sRem($userKey, $expiredId);
            }

            return $sessions;
        } catch (RedisException) {
            return [];
        }
    }

    #[Override]
    public function revokeSession(string $sessionId): bool
    {
        return $this->destroy($sessionId);
    }

    #[Override]
    public function getActiveSessions(string $userId): int
    {
        try {
            $userKey = $this->userSessionsKey($userId);
            /** @var list<string> $sessionIds */
            $sessionIds = $this->redis->sMembers($userKey);
            $activeCount = 0;
            $expiredIds = [];

            foreach ($sessionIds as $sessionId) {
                if ($this->redis->exists($this->sessionKey($sessionId))) {
                    $activeCount++;
                } else {
                    $expiredIds[] = $sessionId;
                }
            }

            // Clean up stale references
            foreach ($expiredIds as $expiredId) {
                $this->redis->sRem($userKey, $expiredId);
            }

            return $activeCount;
        } catch (RedisException) {
            return 0;
        }
    }

    /**
     * Atomically add a session for a user, respecting the concurrent session limit.
     *
     * Uses a Lua script to ensure atomic check-and-add. Returns true if the session
     * was added (under limit), false if the limit would be exceeded.
     *
     * @throws SecurityException If Redis communication fails
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function atomicAddSession(string $userId, string $sessionId, int $maxSessions): bool
    {
        try {
            $userKey = $this->userSessionsKey($userId);

            // First, clean up expired sessions from the set
            $this->cleanExpiredUserSessions($userId);

            /** @var int $result */
            $result = $this->redis->eval(
                self::LUA_CONCURRENCY_CHECK,
                [$userKey, (string) $maxSessions, $sessionId],
                1,
            );

            return $result === 1;
        } catch (RedisException $e) {
            throw SecurityException::sessionEncryptionFailed('Redis error: ' . $e->getMessage());
        }
    }

    /**
     * Set metadata context for the current session write operation.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function setSessionContext(?string $userId, string $ipAddress, string $userAgent): void
    {
        $this->currentUserId = $userId;
        $this->currentIpAddress = $ipAddress;
        $this->currentUserAgent = $userAgent;
    }

    private function cleanExpiredUserSessions(string $userId): void
    {
        try {
            $userKey = $this->userSessionsKey($userId);
            /** @var list<string> $sessionIds */
            $sessionIds = $this->redis->sMembers($userKey);

            foreach ($sessionIds as $sessionId) {
                if (!$this->redis->exists($this->sessionKey($sessionId))) {
                    $this->redis->sRem($userKey, $sessionId);
                }
            }
        } catch (RedisException) {
            // Best-effort cleanup
        }
    }

    private function sessionKey(string $id): string
    {
        return $this->prefix . 'session:' . $id;
    }

    private function userSessionsKey(string $userId): string
    {
        return $this->prefix . 'user:' . $userId;
    }
}
