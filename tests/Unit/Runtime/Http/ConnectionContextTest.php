<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Http\ConnectionContext;
use Socket;

use function socket_close;
use function socket_create;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(ConnectionContext::class)]
#[RequiresPhpExtension('sockets')]
final class ConnectionContextTest extends TestCase
{
    private Socket $socket;

    protected function setUp(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertNotFalse($socket);
        $this->socket = $socket;
    }

    protected function tearDown(): void
    {
        @socket_close($this->socket);
    }

    #[Test]
    public function it_initializes_with_defaults(): void
    {
        $ctx = new ConnectionContext($this->socket);

        self::assertSame('', $ctx->readBuffer);
        self::assertSame(0, $ctx->bytesRead);
        self::assertSame('idle', $ctx->currentRequestPhase);
        self::assertSame(100, $ctx->keepAliveRemaining);
        self::assertGreaterThan(0.0, $ctx->lastActivity);
        self::assertGreaterThan(0.0, $ctx->phaseStartedAt);
    }

    #[Test]
    public function it_accepts_custom_max_keep_alive(): void
    {
        $ctx = new ConnectionContext($this->socket, maxKeepAliveRequests: 50);

        self::assertSame(50, $ctx->keepAliveRemaining);
    }

    #[Test]
    public function enter_phase_updates_state(): void
    {
        $ctx = new ConnectionContext($this->socket);
        $beforeTime = $ctx->phaseStartedAt;

        // Small delay to ensure timestamps differ
        usleep(1000);
        $ctx->enterPhase('headers');

        self::assertSame('headers', $ctx->currentRequestPhase);
        self::assertGreaterThanOrEqual($beforeTime, $ctx->phaseStartedAt);
        self::assertSame($ctx->phaseStartedAt, $ctx->lastActivity);
    }

    #[Test]
    public function append_to_buffer_accumulates_data(): void
    {
        $ctx = new ConnectionContext($this->socket);

        $ctx->appendToBuffer('GET / HTTP');
        self::assertSame('GET / HTTP', $ctx->readBuffer);
        self::assertSame(10, $ctx->bytesRead);

        $ctx->appendToBuffer("/1.1\r\n");
        self::assertSame("GET / HTTP/1.1\r\n", $ctx->readBuffer);
        self::assertSame(16, $ctx->bytesRead);
    }

    #[Test]
    public function consume_buffer_removes_from_front(): void
    {
        $ctx = new ConnectionContext($this->socket);
        $ctx->appendToBuffer('Hello, World!');

        $consumed = $ctx->consumeBuffer(7);

        self::assertSame('Hello, ', $consumed);
        self::assertSame('World!', $ctx->readBuffer);
    }

    #[Test]
    public function decrement_keep_alive_counts_down(): void
    {
        $ctx = new ConnectionContext($this->socket, maxKeepAliveRequests: 3);

        self::assertTrue($ctx->decrementKeepAlive()); // 2 remaining
        self::assertTrue($ctx->decrementKeepAlive()); // 1 remaining
        self::assertFalse($ctx->decrementKeepAlive()); // 0 remaining
    }

    #[Test]
    public function it_exposes_the_socket(): void
    {
        $ctx = new ConnectionContext($this->socket);

        self::assertSame($this->socket, $ctx->socket);
    }
}
