<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Pulsar\Api\Api;

/**
 * WebSocket frame opcodes (RFC 6455 Section 5.2).
 */
#[Api(since: '1.0.0')]
enum WebSocketOpcode: int
{
    case Continuation = 0x0;
    case Text = 0x1;
    case Binary = 0x2;
    case Close = 0x8;
    case Ping = 0x9;
    case Pong = 0xA;

    /**
     * Whether this opcode represents a control frame.
     */
    public function isControl(): bool
    {
        return $this->value >= 0x8;
    }

    /**
     * Whether this opcode represents a data frame.
     */
    public function isData(): bool
    {
        return $this->value < 0x8;
    }
}
