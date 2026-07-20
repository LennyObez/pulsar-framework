<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Runtime\ResettableInterface;
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
    public function resetRequestStateClearsStateSoSessionsDoNotBleedOnPersistentWorkers(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        self::assertInstanceOf(ResettableInterface::class, $manager);

        $manager->start();
        $manager->set('user_id', 42);
        $originalId = $manager->id();

        self::assertTrue($manager->isStarted());
        self::assertTrue($manager->has('user_id'));

        // The persistent-worker request boundary: the singleton is reused, so
        // its state must be wiped or the next user inherits this session.
        $manager->resetRequestState();

        self::assertFalse($manager->isStarted(), 'the session must no longer be started after reset');

        // A fresh start (as the next request would do) must yield an empty
        // session with a new id — the previous user's data must not survive.
        $manager->start();
        self::assertNotSame($originalId, $manager->id(), 'the new request must not reuse the prior id');
        self::assertFalse($manager->has('user_id'), "the previous user's data must not survive");
        self::assertSame([], $manager->all());
    }

    #[Test]
    public function startInitializesSession(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        self::assertFalse($manager->isStarted());

        $manager->start();

        self::assertTrue($manager->isStarted());
        self::assertNotEmpty($manager->id());
    }

    #[Test]
    public function getSetHasRemoveOperations(): void
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
    public function allReturnsAllData(): void
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
    public function getReturnsDefaultWhenKeyMissing(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        self::assertNull($manager->get('nonexistent'));
        self::assertSame('fallback', $manager->get('nonexistent', 'fallback'));
    }

    #[Test]
    public function getThrowsWhenNotStarted(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $_ = $manager->get('key');
    }

    #[Test]
    public function setThrowsWhenNotStarted(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $manager->set('key', 'value');
    }

    #[Test]
    public function hasThrowsWhenNotStarted(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $manager->has('key');
    }

    #[Test]
    public function removeThrowsWhenNotStarted(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $manager->remove('key');
    }

    #[Test]
    public function allThrowsWhenNotStarted(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $manager->all();
    }

    #[Test]
    public function regenerateThrowsWhenNotStarted(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $manager->regenerate();
    }

    #[Test]
    public function regenerateChangesIdPreservesData(): void
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
    public function destroyClearsEverything(): void
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
    public function savePersistsDataToHandler(): void
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
    public function metadataTracking(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        $metadata = $manager->metadata;

        self::assertNotNull($metadata);
        self::assertGreaterThan(0, $metadata->createdAt);
        self::assertGreaterThan(0, $metadata->lastActivity);
    }

    #[Test]
    public function startIsIdempotent(): void
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
    public function startWithRequestAppliesValidators(): void
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
    public function startWithRequestFailsValidation(): void
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
        // Authenticated session (identity in session data, the gate's source of
        // truth): a validator failure must force re-authentication (PCI-DSS 8.2.8),
        // so it still throws rather than silently regenerating (the anonymous
        // regeneration path is covered in SessionAdversarialTest).
        $manager->set('_pulsar_identity', ['id' => 'user-42']);
        $manager->save();
        $sessionId = $manager->id();

        // Second request: a different UA on the authenticated session must fail
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
    public function concurrentSessionLimitEnforcement(): void
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
        $dbHandler->setSessionContext('user-1', '10.0.0.1', 'Agent');
        $dbHandler->write('s1', 'data1');
        $dbHandler->setSessionContext('user-1', '10.0.0.2', 'Agent');
        $dbHandler->write('s2', 'data2');

        $manager = new SessionManager($dbHandler, $config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Concurrent session limit exceeded');

        $manager->enforceConcurrencyLimit('user-1');
    }

    #[Test]
    public function concurrentSessionLimitPassesUnderLimit(): void
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
        $dbHandler->setSessionContext('user-1', '10.0.0.1', 'Agent');
        $dbHandler->write('s1', 'data1');

        $manager = new SessionManager($dbHandler, $config);

        // Should not throw — only 1 session, limit is 5
        $manager->enforceConcurrencyLimit('user-1');
        self::assertSame(1, $dbHandler->getActiveSessions('user-1'));
    }

    #[Test]
    public function setUserIdUpdatesMetadata(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        self::assertNull($manager->metadata?->userId);

        $manager->setUserId('user-42');

        self::assertSame('user-42', $manager->metadata?->userId);
    }

    #[Test]
    public function fixationProtectionRegenerateChangesId(): void
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
    public function closeSavesAndClosesHandler(): void
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
    public function gcDelegatesToHandler(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $result = $manager->gc();

        // ArrayHandler gc always returns 0
        self::assertSame(0, $result);
    }

    #[Test]
    public function getHandlerReturnsHandler(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        self::assertSame($this->handler, $manager->getHandler());
    }

    #[Test]
    public function getConfigReturnsConfig(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        self::assertSame($this->config, $manager->getConfig());
    }

    #[Test]
    public function saveWhenNotStartedIsNoOp(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        // Should not throw — just returns early
        $manager->save();

        self::assertFalse($manager->isStarted());
    }

    #[Test]
    public function closeWhenNotStartedIsNoOp(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        // Should not throw
        $manager->close();

        self::assertFalse($manager->isStarted());
    }

    #[Test]
    public function startWithRequestWithInvalidCookieIdGeneratesNew(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'TestAgent'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => 'invalid-not-64-hex-chars'],
        );

        $manager->startWithRequest($request);

        // Should have generated a new valid session ID (64 hex chars)
        self::assertTrue($manager->isStarted());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $manager->id());
    }

    #[Test]
    public function startWithRequestWithEmptyCookieGeneratesNew(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'TestAgent'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $manager->startWithRequest($request);

        self::assertTrue($manager->isStarted());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $manager->id());
    }

    #[Test]
    public function startWithRequestIsIdempotent(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'TestAgent'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $manager->startWithRequest($request);
        $id = $manager->id();

        // Second call should be a no-op
        $manager->startWithRequest($request);

        self::assertSame($id, $manager->id());
    }

    #[Test]
    public function enforceConcurrencyLimitSkipsNonSupportingHandler(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        // ArrayHandler does not support concurrency control
        // Should return without throwing
        $manager->enforceConcurrencyLimit('user-1');

        self::assertFalse($this->handler->supportsConcurrencyControl());
    }

    #[Test]
    public function regenerateWithoutDeletingOldSession(): void
    {
        $manager = new SessionManager($this->handler, $this->config);
        $manager->start();

        $manager->set('data', 'preserved');
        $manager->save();
        $oldId = $manager->id();

        $manager->regenerate(deleteOldSession: false);

        $newId = $manager->id();

        self::assertNotSame($oldId, $newId);
        self::assertSame('preserved', $manager->get('data'));

        // Old session data should still exist in the handler
        $oldData = $this->handler->read($oldId);
        self::assertNotEmpty($oldData);
    }

    #[Test]
    public function startWithRequestMetadataCapture(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Mozilla/5.0'],
            serverParams: ['REMOTE_ADDR' => '192.168.1.100'],
        );

        $manager->startWithRequest($request);

        $metadata = $manager->metadata;
        self::assertNotNull($metadata);
        self::assertSame('192.168.1.100', $metadata->ipAddress);
        self::assertSame('Mozilla/5.0', $metadata->userAgent);
    }

    #[Test]
    public function setUserIdWhenNotStartedThrows(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $manager->setUserId('user-1');
    }

    #[Test]
    public function destroyWithEmptySessionIdIsNoOp(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        // Destroy without ever starting — sessionId is empty
        $manager->destroy();

        self::assertFalse($manager->isStarted());
    }

    #[Test]
    public function startWithRequestNonStringRemoteAddr(): void
    {
        $manager = new SessionManager($this->handler, $this->config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'TestAgent'],
            serverParams: [],
        );

        $manager->startWithRequest($request);

        $metadata = $manager->metadata;
        self::assertNotNull($metadata);
        self::assertSame('', $metadata->ipAddress);
    }
}
