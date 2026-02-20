<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Handler\ArrayHandler;

#[CoversClass(ArrayHandler::class)]
final class ArrayHandlerTest extends TestCase
{
    private ArrayHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new ArrayHandler();
    }

    #[Test]
    public function readReturnsEmptyStringForNonexistentSession(): void
    {
        self::assertSame('', $this->handler->read('nonexistent-id'));
    }

    #[Test]
    public function writeAndReadRoundtrip(): void
    {
        $data = 'serialized_session_data';

        self::assertTrue($this->handler->write('session-1', $data));
        self::assertSame($data, $this->handler->read('session-1'));
    }

    #[Test]
    public function destroyRemovesSessionData(): void
    {
        $this->handler->write('session-1', 'data');

        self::assertTrue($this->handler->destroy('session-1'));
        self::assertSame('', $this->handler->read('session-1'));
    }

    #[Test]
    public function gcReturnsZero(): void
    {
        self::assertSame(0, $this->handler->gc(3600));
    }

    #[Test]
    public function supportsConcurrencyControlReturnsFalse(): void
    {
        self::assertFalse($this->handler->supportsConcurrencyControl());
    }

    #[Test]
    public function supportsSessionListingReturnsFalse(): void
    {
        self::assertFalse($this->handler->supportsSessionListing());
    }

    #[Test]
    public function supportsRevocationReturnsFalse(): void
    {
        self::assertFalse($this->handler->supportsRevocation());
    }

    #[Test]
    public function listSessionsThrowsNotSupported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support session listing');

        $this->handler->listSessions('user-1');
    }

    #[Test]
    public function revokeSessionThrowsNotSupported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support session revocation');

        $this->handler->revokeSession('session-1');
    }

    #[Test]
    public function getActiveSessionsThrowsNotSupported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support concurrency control');

        $this->handler->getActiveSessions('user-1');
    }

    #[Test]
    public function openReturnsTrue(): void
    {
        self::assertTrue($this->handler->open('', 'TEST_SESSION'));
    }

    #[Test]
    public function closeReturnsTrue(): void
    {
        self::assertTrue($this->handler->close());
    }
}
