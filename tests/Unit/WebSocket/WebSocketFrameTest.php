<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\WebSocket\WebSocketFrame;
use Pulsar\WebSocket\WebSocketOpcode;

use function ord;

#[CoversClass(WebSocketFrame::class)]
final class WebSocketFrameTest extends TestCase
{
    #[Test]
    public function textFrameFactory(): void
    {
        $frame = WebSocketFrame::text('hello');

        self::assertSame(WebSocketOpcode::Text, $frame->opcode);
        self::assertSame('hello', $frame->payload);
        self::assertTrue($frame->fin);
        self::assertFalse($frame->masked);
    }

    #[Test]
    public function binaryFrameFactory(): void
    {
        $frame = WebSocketFrame::binary("\x00\x01\x02");

        self::assertSame(WebSocketOpcode::Binary, $frame->opcode);
        self::assertSame("\x00\x01\x02", $frame->payload);
    }

    #[Test]
    public function closeFrameFactory(): void
    {
        $frame = WebSocketFrame::close(1000, 'goodbye');

        self::assertSame(WebSocketOpcode::Close, $frame->opcode);
        self::assertTrue($frame->isControl());

        // Close frame payload is 2-byte code + reason
        self::assertSame(pack('n', 1000) . 'goodbye', $frame->payload);
    }

    #[Test]
    public function pingFrameFactory(): void
    {
        $frame = WebSocketFrame::ping('data');

        self::assertSame(WebSocketOpcode::Ping, $frame->opcode);
        self::assertSame('data', $frame->payload);
        self::assertTrue($frame->isControl());
    }

    #[Test]
    public function pongFrameFactory(): void
    {
        $frame = WebSocketFrame::pong('data');

        self::assertSame(WebSocketOpcode::Pong, $frame->opcode);
        self::assertSame('data', $frame->payload);
        self::assertTrue($frame->isControl());
    }

    #[Test]
    public function isControlReturnsFalseForDataFrames(): void
    {
        self::assertFalse(WebSocketFrame::text('hi')->isControl());
        self::assertFalse(WebSocketFrame::binary('hi')->isControl());
    }

    #[Test]
    public function encodeSmallTextFrame(): void
    {
        $frame = WebSocketFrame::text('Hi');
        $encoded = $frame->encode();

        // Byte 0: FIN=1, opcode=1 → 0x81
        self::assertSame(0x81, ord($encoded[0]));
        // Byte 1: MASK=0, length=2 → 0x02
        self::assertSame(0x02, ord($encoded[1]));
        // Payload
        self::assertSame('Hi', substr($encoded, 2));
    }

    #[Test]
    public function encodeMediumPayload(): void
    {
        $payload = str_repeat('A', 200);
        $frame = WebSocketFrame::text($payload);
        $encoded = $frame->encode();

        // Byte 1: MASK=0, length=126 (extended 16-bit)
        self::assertSame(126, ord($encoded[1]));
        // Bytes 2-3: 200 in network byte order
        $unpacked = unpack('n', substr($encoded, 2, 2));
        self::assertIsArray($unpacked);
        self::assertSame(200, $unpacked[1]);
    }

    #[Test]
    public function encodeLargePayload(): void
    {
        $payload = str_repeat('B', 70000);
        $frame = WebSocketFrame::text($payload);
        $encoded = $frame->encode();

        // Byte 1: MASK=0, length=127 (extended 64-bit)
        self::assertSame(127, ord($encoded[1]));
        // Bytes 2-9: 70000 in 64-bit network byte order
        $unpacked = unpack('J', substr($encoded, 2, 8));
        self::assertIsArray($unpacked);
        self::assertSame(70000, $unpacked[1]);
    }

    #[Test]
    public function encodeMaskedFrame(): void
    {
        $maskKey = "\x12\x34\x56\x78";
        $frame = new WebSocketFrame(
            opcode: WebSocketOpcode::Text,
            payload: 'test',
            fin: true,
            masked: true,
            maskKey: $maskKey,
        );

        $encoded = $frame->encode();

        // Byte 1 should have MASK bit set
        self::assertSame(0x80 | 4, ord($encoded[1]));

        // Mask key at offset 2
        self::assertSame($maskKey, substr($encoded, 2, 4));

        // Unmasked payload should match original
        $maskedPayload = substr($encoded, 6);
        $unmasked = WebSocketFrame::applyMask($maskedPayload, $maskKey);
        self::assertSame('test', $unmasked);
    }

    #[Test]
    public function applyMaskIsItsOwnInverse(): void
    {
        $data = 'Hello, WebSocket!';
        $key = "\xAB\xCD\xEF\x01";

        $masked = WebSocketFrame::applyMask($data, $key);
        self::assertNotSame($data, $masked);

        $unmasked = WebSocketFrame::applyMask($masked, $key);
        self::assertSame($data, $unmasked);
    }

    #[Test]
    public function applyMaskWithEmptyData(): void
    {
        self::assertSame('', WebSocketFrame::applyMask('', "\x00\x00\x00\x00"));
    }

    #[Test]
    public function closeFrameWithDefaultCode(): void
    {
        $frame = WebSocketFrame::close();

        self::assertSame(WebSocketOpcode::Close, $frame->opcode);
        // Default code 1000, empty reason
        self::assertSame(pack('n', 1000), $frame->payload);
    }

    #[Test]
    public function pingWithEmptyPayload(): void
    {
        $frame = WebSocketFrame::ping();

        self::assertSame('', $frame->payload);
    }
}
