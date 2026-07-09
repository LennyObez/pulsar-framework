<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\SessionMetadata;

use function bin2hex;
use function json_encode;
use function random_bytes;
use function time;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

#[CoversClass(SessionManager::class)]
final class SessionIdleTimeoutTest extends TestCase
{
    private ArrayHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new ArrayHandler();
    }

    #[Test]
    public function anonymousIdleExpiredSessionIsSilentlyRegenerated(): void
    {
        $config = $this->makeConfig(idleTimeout: 300); // 5 minutes

        // Anonymous session (no userId) idle for 10 minutes: the request must
        // survive with a fresh session rather than surfacing a 500.
        $sessionId = $this->seedSession(
            lastActivity: time() - 600,
        );

        $manager = new SessionManager($this->handler, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'TestAgent'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        $manager->startWithRequest($request); // must NOT throw

        self::assertTrue($manager->isStarted());
        self::assertTrue($manager->recoveredFromExpiry());
        self::assertNotSame($sessionId, $manager->id(), 'A fresh, rotated session id must be minted, not the expired one.');
        self::assertNull($manager->get('test-key'), 'The expired session data must be discarded.');
    }

    #[Test]
    public function authenticatedIdleExpiredSessionThrows(): void
    {
        $config = $this->makeConfig(idleTimeout: 300);

        // Authenticated session: forcing re-authentication is the point of
        // PCI-DSS 8.2.8, so the strict throw is preserved.
        $sessionId = $this->seedSession(
            lastActivity: time() - 600,
            authenticated: true,
        );

        $manager = new SessionManager($this->handler, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'TestAgent'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        try {
            $manager->startWithRequest($request);
            self::fail('An authenticated idle-expired session must throw.');
        } catch (SecurityException $e) {
            self::assertSame(SecurityException::CODE_SESSION_IDLE_EXPIRED, $e->getCode());
            self::assertStringContainsString('Session idle timeout exceeded', $e->getMessage());
        }
    }

    #[Test]
    public function sessionSurvivesWhenWithinIdleTimeout(): void
    {
        $config = $this->makeConfig(idleTimeout: 900); // 15 minutes

        // Create an existing session with recent lastActivity
        $sessionId = $this->seedSession(
            lastActivity: time() - 60, // 1 minute ago
        );

        $manager = new SessionManager($this->handler, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'TestAgent'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        $manager->startWithRequest($request);

        self::assertTrue($manager->isStarted());
        self::assertSame('test-value', $manager->get('test-key'));
    }

    #[Test]
    public function sessionSurvivesWhenIdleTimeoutDisabled(): void
    {
        $config = $this->makeConfig(idleTimeout: 0); // Disabled

        // Create an old session
        $sessionId = $this->seedSession(
            lastActivity: time() - 999999, // Very old
        );

        $manager = new SessionManager($this->handler, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'TestAgent'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        $manager->startWithRequest($request);

        self::assertTrue($manager->isStarted());
    }

    #[Test]
    public function defaultIdleTimeoutIs900Seconds(): void
    {
        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
        );

        self::assertSame(900, $config->idleTimeout);
    }

    #[Test]
    public function idleTimeoutFromArrayParses(): void
    {
        $env = \Pulsar\Config\Environment::load();

        $config = SessionConfig::fromArray([
            'idle_timeout' => 600,
        ], $env);

        self::assertSame(600, $config->idleTimeout);
    }

    #[Test]
    public function idleTimeoutFromArrayDefaultsTo900(): void
    {
        $env = \Pulsar\Config\Environment::load();

        $config = SessionConfig::fromArray([], $env);

        self::assertSame(900, $config->idleTimeout);
    }

    #[Test]
    public function sessionAtExactBoundaryIsNotExpired(): void
    {
        $config = $this->makeConfig(idleTimeout: 300);

        // lastActivity exactly 300 seconds ago (at the boundary)
        $sessionId = $this->seedSession(
            lastActivity: time() - 300,
        );

        $manager = new SessionManager($this->handler, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'TestAgent'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        // At exactly the boundary, idle seconds == idleTimeout, should NOT expire
        // because the check is > (strictly greater than), not >=
        $manager->startWithRequest($request);

        self::assertTrue($manager->isStarted());
    }

    #[Test]
    public function newSessionIsNotAffectedByIdleTimeout(): void
    {
        $config = $this->makeConfig(idleTimeout: 1); // Very short

        $manager = new SessionManager($this->handler, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'TestAgent'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        // New session (no existing metadata) should not trigger idle timeout
        $manager->startWithRequest($request);

        self::assertTrue($manager->isStarted());
    }

    #[Test]
    public function idleExpiredExceptionContainsTimingDetails(): void
    {
        $exception = SecurityException::sessionIdleExpired(1200, 900);

        self::assertStringContainsString('1200 seconds idle', $exception->getMessage());
        self::assertStringContainsString('maximum is 900 seconds', $exception->getMessage());
    }

    private function makeConfig(int $idleTimeout): SessionConfig
    {
        return new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
            idleTimeout: $idleTimeout,
        );
    }

    /**
     * Seed a session with specific metadata into the handler and return its ID.
     */
    private function seedSession(int $lastActivity, bool $authenticated = false): string
    {
        $sessionId = bin2hex(random_bytes(32));

        $metadata = new SessionMetadata(
            createdAt: time() - 3600,
            lastActivity: $lastActivity,
            ipAddress: '127.0.0.1',
            userAgent: 'TestAgent',
        );

        $data = ['test-key' => 'test-value'];

        if ($authenticated) {
            // Authenticated sessions carry the guard's identity key in session data —
            // the single source of truth the PCI-DSS 8.2.8 idle gate keys off.
            $data['_pulsar_identity'] = ['id' => 'user-123'];
        }

        $stored = [
            'data' => $data,
            '_pulsar_meta' => $metadata->toArray(),
        ];

        $opened = $this->handler->open('', 'TEST_SESSION');
        self::assertTrue($opened, 'Session handler should open successfully');

        // Pulsar 1.0.0-rc.12 stores sessions as JSON, not PHP serialize(),
        // to eliminate the unserialize() attack surface (HIGH-4 / CWE-502).
        $this->handler->write($sessionId, json_encode(
            $stored,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $sessionId;
    }
}
