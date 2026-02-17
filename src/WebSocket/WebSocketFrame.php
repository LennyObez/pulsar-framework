<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Pulsar\Api\Api;

use function chr;
use function strlen;

/**
 * Represents a WebSocket frame (RFC 6455 Section 5).
 */
#[Api(since: '1.0.0')]
final readonly class WebSocketFrame
{
    public function __construct(
        public WebSocketOpcode $opcode,
        public string $payload,
        public bool $fin = true,
        public bool $masked = false,
        public string $maskKey = '',
    ) {}

    /**
     * Create a text frame.
     */
    public static function text(string $payload): self
    {
        return new self(WebSocketOpcode::Text, $payload);
    }

    /**
     * Create a binary frame.
     */
    public static function binary(string $payload): self
    {
        return new self(WebSocketOpcode::Binary, $payload);
    }

    /**
     * Create a close frame.
     */
    public static function close(int $code = 1000, string $reason = ''): self
    {
        $payload = pack('n', $code) . $reason;

        return new self(WebSocketOpcode::Close, $payload);
    }

    /**
     * Create a ping frame.
     */
    public static function ping(string $payload = ''): self
    {
        return new self(WebSocketOpcode::Ping, $payload);
    }

    /**
     * Create a pong frame.
     */
    public static function pong(string $payload = ''): self
    {
        return new self(WebSocketOpcode::Pong, $payload);
    }

    /**
     * Whether this is a control frame.
     */
    public function isControl(): bool
    {
        return $this->opcode->isControl();
    }

    /**
     * Encode this frame to wire format.
     */
    public function encode(): string
    {
        $firstByte = ($this->fin ? 0x80 : 0x00) | $this->opcode->value;
        $payloadLength = strlen($this->payload);

        $frame = chr($firstByte);

        $maskBit = $this->masked ? 0x80 : 0x00;

        if ($payloadLength < 126) {
            $frame .= chr(($maskBit | $payloadLength) & 0xFF);
        } elseif ($payloadLength < 65536) {
            $frame .= chr($maskBit | 126);
            $frame .= pack('n', $payloadLength);
        } else {
            $frame .= chr($maskBit | 127);
            $frame .= pack('J', $payloadLength);
        }

        if ($this->masked && strlen($this->maskKey) === 4) {
            $frame .= $this->maskKey;
            $frame .= self::applyMask($this->payload, $this->maskKey);
        } else {
            $frame .= $this->payload;
        }

        return $frame;
    }

    /**
     * Apply XOR mask to payload data (RFC 6455 Section 5.3).
     */
    public static function applyMask(string $data, string $maskKey): string
    {
        $masked = '';
        $dataLength = strlen($data);

        for ($i = 0; $i < $dataLength; $i++) {
            $masked .= $data[$i] ^ $maskKey[$i % 4];
        }

        return $masked;
    }
}
