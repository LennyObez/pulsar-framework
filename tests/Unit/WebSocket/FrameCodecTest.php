<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\WebSocket\Internal\FrameCodec;
use Pulsar\WebSocket\WebSocketFrame;
use Pulsar\WebSocket\WebSocketOpcode;

use function strlen;

#[CoversClass(FrameCodec::class)]
final class FrameCodecTest extends TestCase
{
    private FrameCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new FrameCodec();
    }

    #[Test]
    public function decodeUnmaskedTextFrame(): void
    {
        $frame = WebSocketFrame::text('Hello');
        $encoded = $frame->encode();

        $result = $this->codec->decode($encoded);

        self::assertNotNull($result);
        [$decoded, $consumed] = $result;
        self::assertSame(WebSocketOpcode::Text, $decoded->opcode);
        self::assertSame('Hello', $decoded->payload);
        self::assertTrue($decoded->fin);
        self::assertSame(strlen($encoded), $consumed);
    }

    #[Test]
    public function decodeMaskedTextFrame(): void
    {
        $maskKey = "\x37\xFA\x21\x3D";
        $frame = new WebSocketFrame(
            opcode: WebSocketOpcode::Text,
            payload: 'Hello',
            fin: true,
            masked: true,
            maskKey: $maskKey,
        );

        $encoded = $frame->encode();
        $result = $this->codec->decode($encoded);

        self::assertNotNull($result);
        [$decoded, $consumed] = $result;
        self::assertSame('Hello', $decoded->payload);
        // The decoder unmasks the payload in place, so the decoded frame must
        // carry no residual mask state — otherwise a re-encode double-XORs and
        // corrupts the payload (see decodedMaskedFrameDropsMaskState...).
        self::assertFalse($decoded->masked);
        self::assertSame('', $decoded->maskKey);
        self::assertSame(strlen($encoded), $consumed);
    }

    #[Test]
    public function decodeMediumPayload(): void
    {
        $payload = str_repeat('X', 300);
        $frame = WebSocketFrame::text($payload);
        $encoded = $frame->encode();

        $result = $this->codec->decode($encoded);

        self::assertNotNull($result);
        [$decoded] = $result;
        self::assertSame($payload, $decoded->payload);
    }

    #[Test]
    public function decodeLargePayload(): void
    {
        $payload = str_repeat('Y', 70000);
        $frame = WebSocketFrame::text($payload);
        $encoded = $frame->encode();

        $result = $this->codec->decode($encoded);

        self::assertNotNull($result);
        [$decoded] = $result;
        self::assertSame(70000, strlen($decoded->payload));
    }

    #[Test]
    public function decodeCloseFrame(): void
    {
        $frame = WebSocketFrame::close(1000, 'bye');
        $encoded = $frame->encode();

        $result = $this->codec->decode($encoded);

        self::assertNotNull($result);
        [$decoded] = $result;
        self::assertSame(WebSocketOpcode::Close, $decoded->opcode);
        self::assertSame(pack('n', 1000) . 'bye', $decoded->payload);
    }

    #[Test]
    public function decodePingFrame(): void
    {
        $frame = WebSocketFrame::ping('ping-data');
        $encoded = $frame->encode();

        $result = $this->codec->decode($encoded);

        self::assertNotNull($result);
        [$decoded] = $result;
        self::assertSame(WebSocketOpcode::Ping, $decoded->opcode);
        self::assertSame('ping-data', $decoded->payload);
    }

    #[Test]
    public function decodeIncompleteBufferReturnsNull(): void
    {
        self::assertNull($this->codec->decode(''));
        self::assertNull($this->codec->decode("\x81"));
    }

    #[Test]
    public function decodeIncompletePayloadReturnsNull(): void
    {
        // Header says 5 bytes, but only 2 bytes of payload
        $buffer = "\x81\x05Hi";

        self::assertNull($this->codec->decode($buffer));
    }

    #[Test]
    public function decodeIncompleteMediumLengthReturnsNull(): void
    {
        // Extended length indicator (126) but no extended bytes
        $buffer = "\x81\x7E";

        self::assertNull($this->codec->decode($buffer));
    }

    #[Test]
    public function decodeIncompleteLargeLengthReturnsNull(): void
    {
        // Extended length indicator (127) but insufficient bytes
        $buffer = "\x81\x7F\x00\x00";

        self::assertNull($this->codec->decode($buffer));
    }

    #[Test]
    public function decodeIncompleteMaskKeyReturnsNull(): void
    {
        // Masked bit set, length=1, but no mask key bytes
        $buffer = "\x81\x81";

        self::assertNull($this->codec->decode($buffer));
    }

    #[Test]
    public function decodeUnknownOpcodeReturnsNull(): void
    {
        // opcode 0x3 is reserved
        $buffer = "\x83\x00";

        self::assertNull($this->codec->decode($buffer));
    }

    #[Test]
    public function encodeUsesFrameEncoding(): void
    {
        $frame = WebSocketFrame::text('test');
        $fromCodec = $this->codec->encode($frame);
        $fromFrame = $frame->encode();

        self::assertSame($fromFrame, $fromCodec);
    }

    #[Test]
    public function roundTripPreservesPayload(): void
    {
        $original = WebSocketFrame::text('Round trip test!');
        $encoded = $this->codec->encode($original);
        $result = $this->codec->decode($encoded);

        self::assertNotNull($result);
        [$decoded] = $result;
        self::assertSame($original->payload, $decoded->payload);
        self::assertSame($original->opcode, $decoded->opcode);
        self::assertSame($original->fin, $decoded->fin);
    }

    #[Test]
    public function roundTripBinaryFrame(): void
    {
        $binaryData = "\x00\xFF\x42\x13\x37";
        $original = WebSocketFrame::binary($binaryData);
        $encoded = $this->codec->encode($original);
        $result = $this->codec->decode($encoded);

        self::assertNotNull($result);
        [$decoded] = $result;
        self::assertSame($binaryData, $decoded->payload);
    }

    #[Test]
    public function payloadExceedingMaxSizeReturnsNull(): void
    {
        $codec = new FrameCodec(maxPayloadSize: 10);
        $frame = WebSocketFrame::text(str_repeat('A', 20));
        $encoded = $frame->encode();

        self::assertNull($codec->decode($encoded));
    }

    #[Test]
    public function decodedMaskedFrameDropsMaskStateSoReEncodeDoesNotDoubleXor(): void
    {
        // A client frame arrives masked on the wire.
        $maskKey = "\x37\xFA\x21\x3D";
        $clearText = 'Hello, masked world';
        $wireFrame = new WebSocketFrame(
            opcode: WebSocketOpcode::Text,
            payload: $clearText,
            fin: true,
            masked: true,
            maskKey: $maskKey,
        );

        $result = $this->codec->decode($wireFrame->encode());

        self::assertNotNull($result);
        [$decoded] = $result;

        // Payload was unmasked during decode, so the decoded frame must carry
        // no residual mask state; otherwise a re-encode XORs the clear text
        // a second time and corrupts the forwarded payload.
        self::assertSame($clearText, $decoded->payload);
        self::assertFalse($decoded->masked);
        self::assertSame('', $decoded->maskKey);

        // Re-encode (frame forwarding / echo) and decode again: payload must
        // survive intact rather than become double-XOR garbage.
        $reDecoded = $this->codec->decode($this->codec->encode($decoded));

        self::assertNotNull($reDecoded);
        [$reDecodedFrame] = $reDecoded;
        self::assertSame($clearText, $reDecodedFrame->payload);
    }

    #[Test]
    public function frameWithRsvBitSetReturnsNull(): void
    {
        // FIN + RSV1 (0xC1) + text opcode, unmasked, empty payload.
        // No compression extension is negotiated, so RSV1 is a protocol error.
        $rsv1 = "\xC1\x00";
        self::assertNull($this->codec->decode($rsv1));

        // RSV2 (0xA1) and RSV3 (0x91) are equally invalid.
        self::assertNull($this->codec->decode("\xA1\x00"));
        self::assertNull($this->codec->decode("\x91\x00"));
    }

    #[Test]
    public function fragmentedControlFrameReturnsNull(): void
    {
        // FIN unset (0x08 = Close opcode without 0x80 FIN bit), empty payload.
        // Control frames MUST NOT be fragmented (RFC 6455 Section 5.5).
        self::assertNull($this->codec->decode("\x08\x00")); // Close, FIN=0
        self::assertNull($this->codec->decode("\x09\x00")); // Ping, FIN=0
        self::assertNull($this->codec->decode("\x0A\x00")); // Pong, FIN=0
    }
}
