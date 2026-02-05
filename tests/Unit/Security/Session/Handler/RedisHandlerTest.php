<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session\Handler;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Session\Handler\RedisHandler;
use Redis;

use function count;
use function in_array;
use function is_string;
use function json_decode;

/**
 * RedisHandler tests using a mock Redis client.
 *
 * Verifies handler behavior without requiring a real Redis server.
 */
#[CoversClass(RedisHandler::class)]
#[RequiresPhpExtension('redis')]
final class RedisHandlerTest extends TestCase
{
    private RedisHandlerTestMockRedis $redis;

    private RedisHandler $handler;

    protected function setUp(): void
    {
        $this->redis = new RedisHandlerTestMockRedis();
        $this->handler = new RedisHandler($this->redis, 7200, 'test:');
    }

    #[Test]
    public function test_open_returns_true(): void
    {
        self::assertTrue($this->handler->open('', 'TEST'));
    }

    #[Test]
    public function test_close_returns_true(): void
    {
        self::assertTrue($this->handler->close());
    }

    #[Test]
    public function test_read_returns_empty_for_nonexistent(): void
    {
        self::assertSame('', $this->handler->read('nonexistent'));
    }

    #[Test]
    public function test_write_and_read_roundtrip(): void
    {
        self::assertTrue($this->handler->write('s1', 'session-data'));
        self::assertSame('session-data', $this->handler->read('s1'));
    }

    #[Test]
    public function test_destroy_removes_session(): void
    {
        $this->handler->write('s1', 'data');

        self::assertTrue($this->handler->destroy('s1'));
        self::assertSame('', $this->handler->read('s1'));
    }

    #[Test]
    public function test_gc_returns_zero(): void
    {
        self::assertSame(0, $this->handler->gc(7200));
    }

    #[Test]
    public function test_supports_concurrency_control(): void
    {
        self::assertTrue($this->handler->supportsConcurrencyControl());
    }

    #[Test]
    public function test_supports_session_listing(): void
    {
        self::assertTrue($this->handler->supportsSessionListing());
    }

    #[Test]
    public function test_supports_revocation(): void
    {
        self::assertTrue($this->handler->supportsRevocation());
    }

    #[Test]
    public function test_session_context_stored_with_write(): void
    {
        $this->handler->setSessionContext('user-1', '10.0.0.1', 'Chrome');
        $this->handler->write('s1', 'data');

        // Verify the stored JSON contains user context
        $raw = $this->redis->store['test:session:s1'] ?? null;
        self::assertNotNull($raw);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true);
        self::assertSame('user-1', $decoded['user_id']);
        self::assertSame('10.0.0.1', $decoded['ip_address']);
        self::assertSame('Chrome', $decoded['user_agent']);
    }

    #[Test]
    public function test_write_with_user_adds_to_user_set(): void
    {
        $this->handler->setSessionContext('user-1', '10.0.0.1', 'Chrome');
        $this->handler->write('s1', 'data');

        self::assertContains('s1', $this->redis->sets['test:user:user-1'] ?? []);
    }

    #[Test]
    public function test_destroy_removes_from_user_set(): void
    {
        $this->handler->setSessionContext('user-1', '10.0.0.1', 'Chrome');
        $this->handler->write('s1', 'data');

        $this->handler->destroy('s1');

        self::assertNotContains('s1', $this->redis->sets['test:user:user-1'] ?? []);
    }

    #[Test]
    public function test_get_active_sessions_counts_existing(): void
    {
        $this->handler->setSessionContext('user-1', '10.0.0.1', 'Chrome');
        $this->handler->write('s1', 'data1');
        $this->handler->write('s2', 'data2');

        self::assertSame(2, $this->handler->getActiveSessions('user-1'));
    }

    #[Test]
    public function test_list_sessions_returns_session_data(): void
    {
        $this->handler->setSessionContext('user-1', '10.0.0.1', 'Chrome');
        $this->handler->write('s1', 'data1');

        $sessions = $this->handler->listSessions('user-1');

        self::assertCount(1, $sessions);
        self::assertSame('s1', $sessions[0]['id']);
        self::assertSame('10.0.0.1', $sessions[0]['ip_address']);
        self::assertSame('Chrome', $sessions[0]['user_agent']);
    }

    #[Test]
    public function test_revoke_session_destroys_it(): void
    {
        $this->handler->write('s1', 'data');

        self::assertTrue($this->handler->revokeSession('s1'));
        self::assertSame('', $this->handler->read('s1'));
    }

    #[Test]
    public function test_list_sessions_cleans_expired(): void
    {
        $this->handler->setSessionContext('user-1', '10.0.0.1', 'Chrome');
        $this->handler->write('s1', 'data');

        // Add a stale ID to user set without corresponding session key
        $this->redis->sets['test:user:user-1'][] = 'expired-s2';

        $sessions = $this->handler->listSessions('user-1');

        // Should only return s1, and expired-s2 should have been cleaned from set
        self::assertCount(1, $sessions);
        self::assertNotContains('expired-s2', $this->redis->sets['test:user:user-1'] ?? []);
    }
}

// Guard: only define the mock class when ext-redis is available.
// Without this guard, PHP fatals at class-load time because Redis doesn't exist.
if (class_exists(Redis::class)) {
    /**
     * In-memory Redis mock for unit testing.
     *
     * Extends Redis class to satisfy type hints while providing in-memory storage.
     * Only methods used by RedisHandler are implemented.
     *
     * @psalm-suppress InvalidExtendClass
     */
    class RedisHandlerTestMockRedis extends Redis
    {
        /** @var array<string, string> */
        public array $store = [];

        /** @var array<string, list<string>> */
        public array $sets = [];

        /** @var array<string, int> */
        public array $ttls = [];

        public function __construct()
        {
            // Do NOT call parent — no real Redis connection
        }

        #[Override]
        public function get(string $key): string|false
        {
            return $this->store[$key] ?? false;
        }

        #[Override]
        public function setex(string $key, int $expire, mixed $value): bool
        {
            $this->store[$key] = is_string($value) ? $value : '';
            $this->ttls[$key] = $expire;

            return true;
        }

        #[Override]
        public function del(mixed ...$args): int
        {
            $deleted = 0;

            foreach ($args as $key) {
                $k = is_string($key) ? $key : '';

                if (isset($this->store[$k])) {
                    unset($this->store[$k]);
                    $deleted++;
                }
            }

            return $deleted;
        }

        #[Override]
        public function sAdd(string $key, mixed ...$members): int|false
        {
            if (!isset($this->sets[$key])) {
                $this->sets[$key] = [];
            }

            $added = 0;

            foreach ($members as $member) {
                $memberStr = is_string($member) ? $member : '';

                if (!in_array($memberStr, $this->sets[$key], true)) {
                    $this->sets[$key][] = $memberStr;
                    $added++;
                }
            }

            return $added;
        }

        #[Override]
        public function sRem(string $key, mixed ...$members): int|false
        {
            if (!isset($this->sets[$key])) {
                return 0;
            }

            $removed = 0;

            foreach ($members as $member) {
                $memberStr = is_string($member) ? $member : '';
                $idx = array_search($memberStr, $this->sets[$key], true);

                if ($idx !== false) {
                    array_splice($this->sets[$key], $idx, 1);
                    $removed++;
                }
            }

            return $removed;
        }

        /**
         * @return list<string>
         */
        #[Override]
        public function sMembers(string $key): array
        {
            return $this->sets[$key] ?? [];
        }

        #[Override]
        public function exists(mixed ...$args): int|bool
        {
            $count = 0;

            foreach ($args as $key) {
                $k = is_string($key) ? $key : '';

                if (isset($this->store[$k])) {
                    $count++;
                }
            }

            return $count > 0;
        }

        #[Override]
        public function expire(string $key, int $timeout, ?string $mode = null): bool
        {
            $this->ttls[$key] = $timeout;

            return true;
        }

        /**
         * @param list<string> $keys
         */
        #[Override]
        public function eval(string $script, array $keys = [], int $numKeys = 0): mixed
        {
            // Simplified Lua script emulation for concurrency check
            $setKey = $keys[0] ?? '';
            $maxSessions = (int) ($keys[1] ?? 0);
            $sessionId = $keys[2] ?? '';

            $count = count($this->sets[$setKey] ?? []);

            if ($count >= $maxSessions) {
                return 0;
            }

            $this->sAdd($setKey, $sessionId);

            return 1;
        }
    }
}
