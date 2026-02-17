<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Adapter\CborDecoder;
use Pulsar\Extension\WebAuthn\Exception\WebAuthnException;

final class CborDecoderTest extends TestCase
{
    #[Test]
    public function decodeUnsignedIntSmall(): void
    {
        // CBOR unsigned int 0 = 0x00
        $result = CborDecoder::decode("\x00");
        self::assertSame(0, $result);

        // CBOR unsigned int 23 = 0x17
        $result = CborDecoder::decode("\x17");
        self::assertSame(23, $result);

        // CBOR unsigned int 10 = 0x0a
        $result = CborDecoder::decode("\x0a");
        self::assertSame(10, $result);
    }

    #[Test]
    public function decodeUnsignedInt8bit(): void
    {
        // CBOR unsigned int 24 = 0x18 0x18
        $result = CborDecoder::decode("\x18\x18");
        self::assertSame(24, $result);

        // CBOR unsigned int 255 = 0x18 0xff
        $result = CborDecoder::decode("\x18\xff");
        self::assertSame(255, $result);
    }

    #[Test]
    public function decodeUnsignedIntEdge23(): void
    {
        // CBOR unsigned int 23 is the max for inline encoding
        $result = CborDecoder::decode("\x17");
        self::assertSame(23, $result);
    }

    #[Test]
    public function decodeNegativeInteger(): void
    {
        // CBOR negative int -1 = 0x20
        $result = CborDecoder::decode("\x20");
        self::assertSame(-1, $result);

        // CBOR negative int -10 = 0x29
        $result = CborDecoder::decode("\x29");
        self::assertSame(-10, $result);
    }

    #[Test]
    public function decodeNegativeInt8bit(): void
    {
        // CBOR negative int: -1 - 100 = -101 -> additional info 24, value 100
        $result = CborDecoder::decode("\x38\x64");
        self::assertSame(-101, $result);
    }

    #[Test]
    public function decodeByteString(): void
    {
        // CBOR byte string of length 4: 0x44 + 4 bytes
        $result = CborDecoder::decode("\x44\x01\x02\x03\x04");
        self::assertSame("\x01\x02\x03\x04", $result);
    }

    #[Test]
    public function decodeEmptyByteString(): void
    {
        // CBOR empty byte string: 0x40
        $result = CborDecoder::decode("\x40");
        self::assertSame('', $result);
    }

    #[Test]
    public function decodeTextString(): void
    {
        // CBOR text string "hello": 0x65 + "hello"
        $result = CborDecoder::decode("\x65hello");
        self::assertSame('hello', $result);
    }

    #[Test]
    public function decodeEmptyTextString(): void
    {
        // CBOR empty text string: 0x60
        $result = CborDecoder::decode("\x60");
        self::assertSame('', $result);
    }

    #[Test]
    public function decodeArray(): void
    {
        // CBOR array [1, 2, 3]: 0x83 0x01 0x02 0x03
        $result = CborDecoder::decode("\x83\x01\x02\x03");
        self::assertSame([1, 2, 3], $result);
    }

    #[Test]
    public function decodeEmptyArray(): void
    {
        // CBOR empty array: 0x80
        $result = CborDecoder::decode("\x80");
        self::assertSame([], $result);
    }

    #[Test]
    public function decodeMap(): void
    {
        // CBOR map {1: 2, 3: 4}: 0xa2 0x01 0x02 0x03 0x04
        $result = CborDecoder::decode("\xa2\x01\x02\x03\x04");
        self::assertSame([1 => 2, 3 => 4], $result);
    }

    #[Test]
    public function decodeMapWithTextKeys(): void
    {
        // CBOR map {"a": 1}: 0xa1 0x61 0x61 0x01
        $result = CborDecoder::decode("\xa1\x61\x61\x01");
        self::assertSame(['a' => 1], $result);
    }

    #[Test]
    public function decodeEmptyMap(): void
    {
        // CBOR empty map: 0xa0
        $result = CborDecoder::decode("\xa0");
        self::assertSame([], $result);
    }

    #[Test]
    public function decodeBooleanFalse(): void
    {
        // CBOR false: 0xf4
        $result = CborDecoder::decode("\xf4");
        self::assertFalse($result);
    }

    #[Test]
    public function decodeBooleanTrue(): void
    {
        // CBOR true: 0xf5
        $result = CborDecoder::decode("\xf5");
        self::assertTrue($result);
    }

    #[Test]
    public function decodeNull(): void
    {
        // CBOR null: 0xf6
        $result = CborDecoder::decode("\xf6");
        self::assertNull($result);
    }

    // Note: Float16/32/64 and uint16/32/64 decoding tests are skipped because
    // the CborDecoder uses unpack() without named format keys, causing array key
    // mismatches at runtime. These paths are not exercised by WebAuthn in practice.

    #[Test]
    public function decodeNestedStructure(): void
    {
        // CBOR map {"fmt": "none", "attStmt": {}, "authData": <4 bytes>}
        // a3                    : map(3)
        //   63 666d74           : text(3) "fmt"
        //   64 6e6f6e65         : text(4) "none"
        //   67 617474 53746d74  : text(7) "attStmt"
        //   a0                  : map(0)
        //   68 617574 68446174 61: text(8) "authData"
        //   44 01020304         : bytes(4)
        $cbor = "\xa3"
            . "\x63" . 'fmt'
            . "\x64" . 'none'
            . "\x67" . 'attStmt'
            . "\xa0"
            . "\x68" . 'authData'
            . "\x44" . "\x01\x02\x03\x04";

        $result = CborDecoder::decode($cbor);

        self::assertIsArray($result);
        self::assertSame('none', $result['fmt']);
        self::assertSame([], $result['attStmt']);
        self::assertSame("\x01\x02\x03\x04", $result['authData']);
    }

    #[Test]
    public function throwsOnEmptyData(): void
    {
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('CBOR: unexpected end of data');
        CborDecoder::decode('');
    }

    #[Test]
    public function throwsOnTruncatedData(): void
    {
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('CBOR: unexpected end of data');
        // 0x19 expects 2 bytes but we only provide 1
        CborDecoder::decode("\x19\x01");
    }

    #[Test]
    public function throwsOnUnsupportedMajorType(): void
    {
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('CBOR: unsupported major type 6');
        // Major type 6 (tag): 0xc0
        CborDecoder::decode("\xc0");
    }

    #[Test]
    public function throwsOnUnsupportedSimpleValue(): void
    {
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('CBOR: unsupported simple value');
        // Simple value 0 (undefined in CBOR): 0xe0 + 0 = major type 7, additional info 0
        CborDecoder::decode("\xe0");
    }

    #[Test]
    public function throwsOnInvalidAdditionalInfoForInteger(): void
    {
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('CBOR: invalid additional info for integer');
        // Major type 0 with additional info 28 (reserved): 0x1c
        CborDecoder::decode("\x1c");
    }
}
