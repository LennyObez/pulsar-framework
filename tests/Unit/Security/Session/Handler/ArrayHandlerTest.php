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
    public function test_read_returns_empty_string_for_nonexistent_session(): void
    {
        self::assertSame('', $this->handler->read('nonexistent-id'));
    }

    #[Test]
    public function test_write_and_read_roundtrip(): void
    {
        $data = 'serialized_session_data';

        self::assertTrue($this->handler->write('session-1', $data));
        self::assertSame($data, $this->handler->read('session-1'));
    }

    #[Test]
    public function test_destroy_removes_session_data(): void
    {
        $this->handler->write('session-1', 'data');

        self::assertTrue($this->handler->destroy('session-1'));
        self::assertSame('', $this->handler->read('session-1'));
    }

    #[Test]
    public function test_gc_returns_zero(): void
    {
        self::assertSame(0, $this->handler->gc(3600));
    }

    #[Test]
    public function test_supports_concurrency_control_returns_false(): void
    {
        self::assertFalse($this->handler->supportsConcurrencyControl());
    }

    #[Test]
    public function test_supports_session_listing_returns_false(): void
    {
        self::assertFalse($this->handler->supportsSessionListing());
    }

    #[Test]
    public function test_supports_revocation_returns_false(): void
    {
        self::assertFalse($this->handler->supportsRevocation());
    }

    #[Test]
    public function test_list_sessions_throws_not_supported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support session listing');

        $this->handler->listSessions('user-1');
    }

    #[Test]
    public function test_revoke_session_throws_not_supported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support session revocation');

        $this->handler->revokeSession('session-1');
    }

    #[Test]
    public function test_get_active_sessions_throws_not_supported(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('does not support concurrency control');

        $this->handler->getActiveSessions('user-1');
    }

    #[Test]
    public function test_open_returns_true(): void
    {
        self::assertTrue($this->handler->open('', 'TEST_SESSION'));
    }

    #[Test]
    public function test_close_returns_true(): void
    {
        self::assertTrue($this->handler->close());
    }
}
