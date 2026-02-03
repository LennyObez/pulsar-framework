<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SessionConfig;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Session;

/**
 * Session tests are limited in PHPUnit because native session functions
 * require headers not to have been sent. We test the logic paths that
 * do not depend on actual session_start().
 */
#[CoversClass(Session::class)]
final class SessionTest extends TestCase
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
    public function isNotStartedByDefault(): void
    {
        $session = new Session($this->config);

        // In CLI, session_status() returns PHP_SESSION_NONE
        self::assertFalse($session->isStarted());
    }

    #[Test]
    public function getThrowsIfNotStarted(): void
    {
        $session = new Session($this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $session->get('key');
    }

    #[Test]
    public function setThrowsIfNotStarted(): void
    {
        $session = new Session($this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $session->set('key', 'value');
    }

    #[Test]
    public function hasThrowsIfNotStarted(): void
    {
        $session = new Session($this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $session->has('key');
    }

    #[Test]
    public function removeThrowsIfNotStarted(): void
    {
        $session = new Session($this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $session->remove('key');
    }

    #[Test]
    public function allThrowsIfNotStarted(): void
    {
        $session = new Session($this->config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $session->all();
    }

    #[Test]
    public function idReturnsEmptyStringWhenNoSession(): void
    {
        $session = new Session($this->config);

        self::assertSame('', $session->id());
    }

    #[Test]
    public function destroyWithoutActiveSessionDoesNotThrow(): void
    {
        $session = new Session($this->config);

        // Should not throw even when no session is active
        $session->destroy();

        self::assertFalse($session->isStarted());
    }
}
