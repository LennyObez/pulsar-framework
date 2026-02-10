<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Adapter\CborDecoder;
use Pulsar\Extension\WebAuthn\Exception\WebAuthnException;

#[CoversClass(CborDecoder::class)]
final class CborDecoderTest extends TestCase
{
    // ── Unsigned integers (major type 0) — tiny (0-23) ───────────────

    #[Test]
    #[DataProvider('tinyUnsignedIntProvider')]
    public function decodeTinyUnsignedInteger(string $cbor, int $expected): void
    {
        self::assertSame($expected, CborDecoder::decode($cbor));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function tinyUnsignedIntProvider(): iterable
    {
        yield 'zero' => ["\x00", 0];
        yield 'one' => ["\x01", 1];
        yield 'ten' => ["\x0A", 10];
        yield 'twenty-three' => ["\x17", 23];
    }

    // ── Unsigned integers — 1-byte additional info (24) ──────────────

    #[Test]
    #[DataProvider('oneByteUnsignedIntProvider')]
    public function decodeOneByteUnsignedInteger(string $cbor, int $expected): void
    {
        self::assertSame($expected, CborDecoder::decode($cbor));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function oneByteUnsignedIntProvider(): iterable
    {
        yield 'twenty-four' => ["\x18\x18", 24];
        yield 'byte 100' => ["\x18\x64", 100];
        yield 'byte 255' => ["\x18\xFF", 255];
    }

    // ── Negative integers (major type 1) ─────────────────────────────

    #[Test]
    #[DataProvider('negativeIntProvider')]
    public function decodeNegativeInteger(string $cbor, int $expected): void
    {
        self::assertSame($expected, CborDecoder::decode($cbor));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function negativeIntProvider(): iterable
    {
        yield 'minus one' => ["\x20", -1];
        yield 'minus ten' => ["\x29", -10];
        yield 'minus hundred' => ["\x38\x63", -100];
    }

    // ── Byte strings (major type 2) ──────────────────────────────────

    #[Test]
    public function decodeEmptyByteString(): void
    {
        self::assertSame('', CborDecoder::decode("\x40"));
    }

    #[Test]
    public function decodeByteString(): void
    {
        // 0x44 = major type 2 (byte string), length 4
        $cbor = "\x44\x01\x02\x03\x04";
        $result = CborDecoder::decode($cbor);
        self::assertSame("\x01\x02\x03\x04", $result);
    }

    #[Test]
    public function decodeShortByteString(): void
    {
        // Single byte string
        $cbor = "\x41\xAB";
        self::assertSame("\xAB", CborDecoder::decode($cbor));
    }

    // ── Text strings (major type 3) ──────────────────────────────────

    #[Test]
    public function decodeEmptyTextString(): void
    {
        self::assertSame('', CborDecoder::decode("\x60"));
    }

    #[Test]
    public function decodeTextString(): void
    {
        // 0x65 = major type 3 (text string), length 5
        $cbor = "\x65hello";
        self::assertSame('hello', CborDecoder::decode($cbor));
    }

    #[Test]
    public function decodeTextStringFourChars(): void
    {
        $cbor = "\x64IETF";
        self::assertSame('IETF', CborDecoder::decode($cbor));
    }

    #[Test]
    public function decodeSingleCharTextString(): void
    {
        $cbor = "\x61a";
        self::assertSame('a', CborDecoder::decode($cbor));
    }

    // ── Arrays (major type 4) ────────────────────────────────────────

    #[Test]
    public function decodeEmptyArray(): void
    {
        self::assertSame([], CborDecoder::decode("\x80"));
    }

    #[Test]
    public function decodeArrayOfTinyIntegers(): void
    {
        // [1, 2, 3]
        $cbor = "\x83\x01\x02\x03";
        self::assertSame([1, 2, 3], CborDecoder::decode($cbor));
    }

    #[Test]
    public function decodeNestedArray(): void
    {
        // [1, [2, 3]]
        $cbor = "\x82\x01\x82\x02\x03";
        self::assertSame([1, [2, 3]], CborDecoder::decode($cbor));
    }

    #[Test]
    public function decodeSingleElementArray(): void
    {
        // [0]
        $cbor = "\x81\x00";
        self::assertSame([0], CborDecoder::decode($cbor));
    }

    // ── Maps (major type 5) ──────────────────────────────────────────

    #[Test]
    public function decodeEmptyMap(): void
    {
        self::assertSame([], CborDecoder::decode("\xA0"));
    }

    #[Test]
    public function decodeMapWithIntegerKeys(): void
    {
        // {1: 2, 3: 4}
        $cbor = "\xA2\x01\x02\x03\x04";
        $result = CborDecoder::decode($cbor);
        self::assertSame([1 => 2, 3 => 4], $result);
    }

    #[Test]
    public function decodeMapWithStringKeys(): void
    {
        // {"a": 1, "b": 2}
        $cbor = "\xA2\x61\x61\x01\x61\x62\x02";
        $result = CborDecoder::decode($cbor);
        self::assertSame(['a' => 1, 'b' => 2], $result);
    }

    #[Test]
    public function decodeSingleEntryMap(): void
    {
        // {0: "val"}
        $cbor = "\xA1\x00\x63val";
        $result = CborDecoder::decode($cbor);
        self::assertSame([0 => 'val'], $result);
    }

    // ── Simple values (major type 7) ─────────────────────────────────

    #[Test]
    public function decodeFalse(): void
    {
        self::assertFalse(CborDecoder::decode("\xF4"));
    }

    #[Test]
    public function decodeTrue(): void
    {
        self::assertTrue(CborDecoder::decode("\xF5"));
    }

    #[Test]
    public function decodeNull(): void
    {
        self::assertNull(CborDecoder::decode("\xF6"));
    }

    // ── Error handling ───────────────────────────────────────────────

    #[Test]
    public function decodeThrowsOnEmptyInput(): void
    {
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('unexpected end of data');

        CborDecoder::decode('');
    }

    #[Test]
    public function decodeThrowsOnTruncatedByteString(): void
    {
        $this->expectException(WebAuthnException::class);

        // Text string claiming length 5 but only 2 bytes follow
        CborDecoder::decode("\x65\x68\x69");
    }

    #[Test]
    public function decodeThrowsOnUnsupportedMajorType(): void
    {
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('unsupported major type');

        // Major type 6 (tag) is not supported
        CborDecoder::decode("\xC0\x01");
    }

    #[Test]
    public function decodeThrowsOnUnsupportedSimpleValue(): void
    {
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('unsupported simple value');

        // Simple value 0 (major type 7, additional info 0) — undefined
        CborDecoder::decode("\xE0");
    }

    #[Test]
    public function decodeThrowsOnInvalidAdditionalInfoForInteger(): void
    {
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('invalid additional info');

        // Major type 0, additional info 28 (reserved)
        CborDecoder::decode("\x1C");
    }

    #[Test]
    public function decodeThrowsOnTruncatedUint8(): void
    {
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('unexpected end of data');

        // Claims uint8 (additional info 24) but no byte follows
        CborDecoder::decode("\x18");
    }

    // ── Complex WebAuthn-like structures ─────────────────────────────

    #[Test]
    public function decodeMapWithMixedKeyTypesAndByteStrings(): void
    {
        // {1: "packed", 2: bytes, 3: map}
        $cbor = "\xA3"          // map(3)
            . "\x01"            // key: 1
            . "\x66packed"      // value: "packed" (text string, length 6)
            . "\x02"            // key: 2
            . "\x42\xAB\xCD"   // value: byte string, length 2
            . "\x03"            // key: 3
            . "\xA0";           // value: empty map

        $result = CborDecoder::decode($cbor);

        self::assertIsArray($result);
        self::assertSame('packed', $result[1]);
        self::assertSame("\xAB\xCD", $result[2]);
        self::assertSame([], $result[3]);
    }

    #[Test]
    public function decodeNestedMapStructure(): void
    {
        // {"fmt": "none", "attStmt": {}}
        $cbor = "\xA2"
            . "\x63fmt"       // key: "fmt"
            . "\x64none"      // value: "none"
            . "\x67attStmt"   // key: "attStmt"
            . "\xA0";         // value: {}

        $result = CborDecoder::decode($cbor);

        self::assertSame('none', $result['fmt']);
        self::assertSame([], $result['attStmt']);
    }

    #[Test]
    public function decodeArrayInMap(): void
    {
        // {1: [2, 3]}
        $cbor = "\xA1\x01\x82\x02\x03";
        $result = CborDecoder::decode($cbor);

        self::assertSame([1 => [2, 3]], $result);
    }

    #[Test]
    public function decodeMapWithBoolValues(): void
    {
        // {"a": true, "b": false, "c": null}
        $cbor = "\xA3"
            . "\x61\x61\xF5"   // "a": true
            . "\x61\x62\xF4"   // "b": false
            . "\x61\x63\xF6";  // "c": null

        $result = CborDecoder::decode($cbor);

        self::assertTrue($result['a']);
        self::assertFalse($result['b']);
        self::assertNull($result['c']);
    }

    #[Test]
    public function decodeLargeArray(): void
    {
        // Array of 10 zeros
        $cbor = "\x8A" . str_repeat("\x00", 10);
        $result = CborDecoder::decode($cbor);

        self::assertCount(10, $result);
        self::assertSame(array_fill(0, 10, 0), $result);
    }

    #[Test]
    public function decodeMapWithNegativeIntKeys(): void
    {
        // {-1: 1, -2: 2}
        $cbor = "\xA2\x20\x01\x21\x02";
        $result = CborDecoder::decode($cbor);

        self::assertSame([-1 => 1, -2 => 2], $result);
    }
}
