<?php

declare(strict_types=1);

namespace Pulsar\WebSocket\Internal;

use Pulsar\Api\Internal;
use Pulsar\WebSocket\WebSocketFrame;
use Pulsar\WebSocket\WebSocketOpcode;

use function ord;
use function strlen;
use function substr;
use function unpack;

/**
 * Encodes and decodes WebSocket frames (RFC 6455 Section 5).
 */
#[Internal]
final readonly class FrameCodec
{
    /**
     * Maximum payload size for control frames (125 bytes per RFC 6455).
     */
    private const int MAX_CONTROL_PAYLOAD = 125;

    /**
     * Maximum payload size for data frames (16 MiB default).
     */
    private const int MAX_DATA_PAYLOAD = 16_777_216;

    public function __construct(
        private int $maxPayloadSize = self::MAX_DATA_PAYLOAD,
    ) {}

    /**
     * Attempt to decode a frame from a binary buffer.
     *
     * Returns the decoded frame and the number of bytes consumed,
     * or null if the buffer doesn't contain a complete frame.
     *
     * @return array{WebSocketFrame, int}|null
     */
    public function decode(string $buffer): ?array
    {
        $bufferLength = strlen($buffer);

        if ($bufferLength < 2) {
            return null;
        }

        $firstByte = ord($buffer[0]);
        $secondByte = ord($buffer[1]);

        $fin = ($firstByte & 0x80) !== 0;
        $opcodeValue = $firstByte & 0x0F;
        $opcode = WebSocketOpcode::tryFrom($opcodeValue);

        if ($opcode === null) {
            return null;
        }

        $masked = ($secondByte & 0x80) !== 0;
        $payloadLength = $secondByte & 0x7F;
        $offset = 2;

        if ($payloadLength === 126) {
            if ($bufferLength < 4) {
                return null;
            }

            /** @var array{1: int} $unpacked */
            $unpacked = unpack('n', substr($buffer, 2, 2));
            $payloadLength = $unpacked[1];
            $offset = 4;
        } elseif ($payloadLength === 127) {
            if ($bufferLength < 10) {
                return null;
            }

            /** @var array{1: int} $unpacked */
            $unpacked = unpack('J', substr($buffer, 2, 8));
            $payloadLength = $unpacked[1];
            $offset = 10;
        }

        // Validate payload size
        $maxSize = $opcode->isControl() ? self::MAX_CONTROL_PAYLOAD : $this->maxPayloadSize;

        if ($payloadLength > $maxSize) {
            return null;
        }

        $maskKey = '';

        if ($masked) {
            if ($bufferLength < $offset + 4) {
                return null;
            }

            $maskKey = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($bufferLength < $offset + $payloadLength) {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLength);

        if ($masked && $maskKey !== '') {
            $payload = WebSocketFrame::applyMask($payload, $maskKey);
        }

        $frame = new WebSocketFrame(
            opcode: $opcode,
            payload: $payload,
            fin: $fin,
            masked: $masked,
            maskKey: $maskKey,
        );

        $totalConsumed = $offset + $payloadLength;

        return [$frame, $totalConsumed];
    }

    /**
     * Encode a frame to wire format.
     */
    public function encode(WebSocketFrame $frame): string
    {
        return $frame->encode();
    }
}
