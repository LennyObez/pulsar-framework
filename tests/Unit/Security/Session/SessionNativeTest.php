<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SessionConfig;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Session;

/**
 * Tests for Session using native session functions.
 *
 * Each test runs in a separate process so session_start() works
 * (headers are not yet sent in a fresh process).
 */
#[CoversClass(Session::class)]
final class SessionNativeTest extends TestCase
{
    private SessionConfig $config;

    protected function setUp(): void
    {
        $this->config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: false,
            cookieSameSite: 'Lax',
            regenerateOnPrivilegeChange: true,
        );
    }

    #[Test]
    #[RunInSeparateProcess]
    public function startInitializesSession(): void
    {
        $session = new Session($this->config);

        $session->start();

        self::assertTrue($session->isStarted());
        self::assertNotEmpty($session->id());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function startIsIdempotent(): void
    {
        $session = new Session($this->config);

        $session->start();
        $id = $session->id();

        // Second start should not change anything
        $session->start();

        self::assertSame($id, $session->id());
        self::assertTrue($session->isStarted());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function setAndGetRoundTrip(): void
    {
        $session = new Session($this->config);
        $session->start();

        $session->set('user_id', 42);

        self::assertSame(42, $session->get('user_id'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function getReturnsDefaultWhenKeyMissing(): void
    {
        $session = new Session($this->config);
        $session->start();

        self::assertNull($session->get('nonexistent'));
        self::assertSame('fallback', $session->get('nonexistent', 'fallback'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function hasReturnsTrueForExistingKey(): void
    {
        $session = new Session($this->config);
        $session->start();

        $session->set('exists', 'value');

        self::assertTrue($session->has('exists'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function hasReturnsFalseForMissingKey(): void
    {
        $session = new Session($this->config);
        $session->start();

        self::assertFalse($session->has('missing'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function removeDeletesKey(): void
    {
        $session = new Session($this->config);
        $session->start();

        $session->set('to_remove', 'temporary');
        self::assertTrue($session->has('to_remove'));

        $session->remove('to_remove');

        self::assertFalse($session->has('to_remove'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function allReturnsSessionData(): void
    {
        $session = new Session($this->config);
        $session->start();

        $session->set('name', 'Alice');
        $session->set('role', 'admin');

        $all = $session->all();

        self::assertSame('Alice', $all['name']);
        self::assertSame('admin', $all['role']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function regenerateChangesSessionId(): void
    {
        $session = new Session($this->config);
        $session->start();

        $originalId = $session->id();

        $session->regenerate();

        self::assertNotSame($originalId, $session->id());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function regeneratePreservesData(): void
    {
        $session = new Session($this->config);
        $session->start();

        $session->set('persist', 'across-regeneration');

        $session->regenerate(deleteOldSession: false);

        self::assertSame('across-regeneration', $session->get('persist'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function regenerateThrowsWhenNotStarted(): void
    {
        $session = new Session($this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $session->regenerate();
    }

    #[Test]
    #[RunInSeparateProcess]
    public function destroyClearsSessionAndCookie(): void
    {
        $session = new Session($this->config);
        $session->start();

        $session->set('key1', 'value1');

        $session->destroy();

        self::assertFalse($session->isStarted());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function isStartedReturnsTrueAfterNativeSessionStart(): void
    {
        // Start session natively before creating Session wrapper
        session_start();

        $session = new Session($this->config);

        // Should detect the already-active session
        self::assertTrue($session->isStarted());
    }
}
