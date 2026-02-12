<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\Handler\DatabaseHandler;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\Validator\UserAgentValidator;
use ReflectionClass;

#[CoversClass(SessionManager::class)]
final class SessionManagerTest extends TestCase
{
    private SessionConfig $config;

    private ArrayHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new ArrayHandler();
        $this->config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );
    }

    #[Test]
    public function test_start_initializes_session(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        self::assertFalse($manager->isStarted());

        $manager->start();

        self::assertTrue($manager->isStarted());
        self::assertNotEmpty($manager->id());
    }

    #[Test]
    public function test_get_set_has_remove_operations(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        $manager->set('user', 'alice');
        self::assertSame('alice', $manager->get('user'));
        self::assertTrue($manager->has('user'));

        $manager->remove('user');
        self::assertFalse($manager->has('user'));
        self::assertNull($manager->get('user'));
    }

    #[Test]
    public function test_all_returns_all_data(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        $manager->set('name', 'Alice');
        $manager->set('role', 'admin');
        $manager->set('score', 100);

        self::assertSame([
            'name' => 'Alice',
            'role' => 'admin',
            'score' => 100,
        ], $manager->all());
    }

    #[Test]
    public function test_get_returns_default_when_key_missing(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        self::assertNull($manager->get('nonexistent'));
        self::assertSame('fallback', $manager->get('nonexistent', 'fallback'));
    }

    #[Test]
    public function test_get_throws_when_not_started(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $_ = $manager->get('key');
    }

    #[Test]
    public function test_set_throws_when_not_started(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $manager->set('key', 'value');
    }

    #[Test]
    public function test_has_throws_when_not_started(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $manager->has('key');
    }

    #[Test]
    public function test_remove_throws_when_not_started(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $manager->remove('key');
    }

    #[Test]
    public function test_all_throws_when_not_started(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $manager->all();
    }

    #[Test]
    public function test_regenerate_throws_when_not_started(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $manager->regenerate();
    }

    #[Test]
    public function test_regenerate_changes_id_preserves_data(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        $manager->set('persist', 'across-regeneration');
        $originalId = $manager->id();

        $manager->regenerate();

        self::assertNotSame($originalId, $manager->id());
        self::assertNotEmpty($manager->id());
        self::assertSame('across-regeneration', $manager->get('persist'));
    }

    #[Test]
    public function test_destroy_clears_everything(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        $manager->set('key1', 'value1');
        $manager->set('key2', 'value2');

        $manager->destroy();

        self::assertFalse($manager->isStarted());
        self::assertSame('', $manager->id());
    }

    #[Test]
    public function test_save_persists_data_to_handler(): void
    {
        $handler = new ArrayHandler();
        $manager = new SessionManager($handler, $this->config);
        $manager->start();

        $manager->set('saved_key', 'saved_value');
        $manager->save();

        $sessionId = $manager->id();

        // Create a new manager with the same handler to read back
        $manager2 = new SessionManager($handler, $this->config);

        // We need to set the session ID before starting so it reads back
        // Use reflection to set the ID since there is no public setter
        $reflection = new ReflectionClass($manager2);
        $idProperty = $reflection->getProperty('sessionId');
        $idProperty->setValue($manager2, $sessionId);

        $manager2->start();

        self::assertSame('saved_value', $manager2->get('saved_key'));
    }

    #[Test]
    public function test_metadata_tracking(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        $metadata = $manager->getMetadata();

        self::assertNotNull($metadata);
        self::assertGreaterThan(0, $metadata->createdAt);
        self::assertGreaterThan(0, $metadata->lastActivity);
    }

    #[Test]
    public function test_start_is_idempotent(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        $id = $manager->id();

        // Starting again should not change anything
        $manager->start();

        self::assertSame($id, $manager->id());
        self::assertTrue($manager->isStarted());
    }

    #[Test]
    public function test_start_with_request_applies_validators(): void
    {
        $validator = new UserAgentValidator('strict');
        $manager = new SessionManager($this->handler, $this->config, [$validator]);

        // First request: starts session, stores UA
        $request1 = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Chrome/120'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $manager->startWithRequest($request1);
        $manager->set('key', 'value');
        $manager->save();

        $sessionId = $manager->id();

        // Second request: same UA should pass
        $manager2 = new SessionManager($this->handler, $this->config, [$validator]);
        $request2 = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Chrome/120'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        $manager2->startWithRequest($request2);
        self::assertSame('value', $manager2->get('key'));
    }

    #[Test]
    public function test_start_with_request_fails_validation(): void
    {
        $validator = new UserAgentValidator('strict');
        $manager = new SessionManager($this->handler, $this->config, [$validator]);

        // First request: starts session, stores UA
        $request1 = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Chrome/120'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $manager->startWithRequest($request1);
        $manager->save();
        $sessionId = $manager->id();

        // Second request: different UA should fail
        $manager2 = new SessionManager($this->handler, $this->config, [$validator]);
        $request2 = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Firefox/121'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Session validation failed');

        $manager2->startWithRequest($request2);
    }

    #[Test]
    public function test_concurrent_session_limit_enforcement(): void
    {
        // Use DatabaseHandler which supports concurrency control
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE sessions (
                id VARCHAR(128) PRIMARY KEY,
                user_id VARCHAR(255) NULL,
                data TEXT NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                last_activity INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )',
        );

        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'database',
            encryption: false,
            maxConcurrentSessions: 2,
        );

        $dbHandler = new DatabaseHandler($pdo, 'sessions', 3600);

        // Insert 2 active sessions for user-1
        $dbHandler->setSessionContext('s1', 'user-1', '10.0.0.1', 'Agent');
        $dbHandler->write('s1', 'data1');
        $dbHandler->setSessionContext('s2', 'user-1', '10.0.0.2', 'Agent');
        $dbHandler->write('s2', 'data2');

        $manager = new SessionManager($dbHandler, $config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Concurrent session limit exceeded');

        $manager->enforceConcurrencyLimit('user-1');
    }

    #[Test]
    public function test_concurrent_session_limit_passes_under_limit(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE sessions (
                id VARCHAR(128) PRIMARY KEY,
                user_id VARCHAR(255) NULL,
                data TEXT NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                last_activity INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )',
        );

        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'database',
            encryption: false,
            maxConcurrentSessions: 5,
        );

        $dbHandler = new DatabaseHandler($pdo, 'sessions', 3600);
        $dbHandler->setSessionContext('s1', 'user-1', '10.0.0.1', 'Agent');
        $dbHandler->write('s1', 'data1');

        $manager = new SessionManager($dbHandler, $config);

        // Should not throw — only 1 session, limit is 5
        $manager->enforceConcurrencyLimit('user-1');
        self::assertSame(1, $dbHandler->getActiveSessions('user-1'));
    }

    #[Test]
    public function test_set_user_id_updates_metadata(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        self::assertNull($manager->getMetadata()?->userId);

        $manager->setUserId('user-42');

        self::assertSame('user-42', $manager->getMetadata()?->userId);
    }

    #[Test]
    public function test_fixation_protection_regenerate_changes_id(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        $manager->set('role', 'guest');
        $originalId = $manager->id();

        // Simulate privilege escalation — regenerate ID
        $manager->set('role', 'admin');
        $manager->regenerate(deleteOldSession: true);

        $newId = $manager->id();

        // New ID must differ
        self::assertNotSame($originalId, $newId);
        // Data preserved
        self::assertSame('admin', $manager->get('role'));
        // Old session should be destroyed in handler
        self::assertSame('', $this->handler->read($originalId));
    }

    #[Test]
    public function test_close_saves_and_closes_handler(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        $manager->set('key', 'value');
        $manager->close();

        // After close, data should be persisted
        $sessionId = $manager->id();
        $raw = $this->handler->read($sessionId);
        self::assertNotEmpty($raw);
    }

    #[Test]
    public function test_gc_delegates_to_handler(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $result = $manager->gc();

        // ArrayHandler gc always returns 0
        self::assertSame(0, $result);
    }

    #[Test]
    public function test_get_handler_returns_handler(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        self::assertSame($this->handler, $manager->getHandler());
    }

    #[Test]
    public function test_get_config_returns_config(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        self::assertSame($this->config, $manager->getConfig());
    }
}
