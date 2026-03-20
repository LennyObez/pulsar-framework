<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ProtobufWriter;

use function bin2hex;
use function ord;
use function strlen;

#[CoversClass(ProtobufWriter::class)]
final class ProtobufWriterTest extends TestCase
{
    #[Test]
    public function varintEncodesZero(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarint(0);

        self::assertSame("\x00", $writer->toBytes());
    }

    #[Test]
    public function varintEncodes127AsSingleByte(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarint(127);

        self::assertSame("\x7F", $writer->toBytes());
    }

    #[Test]
    public function varintEncodes128AsTwoBytes(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarint(128);

        // 128 in LEB128: 0x80 0x01
        self::assertSame("\x80\x01", $writer->toBytes());
    }

    #[Test]
    public function varintEncodes300(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarint(300);

        // 300 = 0b100101100 → LEB128: 10101100 00000010 → 0xAC 0x02
        self::assertSame("\xAC\x02", $writer->toBytes());
    }

    #[Test]
    public function varintEncodes150(): void
    {
        $writer = new ProtobufWriter();
        // 150 is the canonical protobuf example: 0x96 0x01
        $writer->writeVarint(150);

        self::assertSame("\x96\x01", $writer->toBytes());
    }

    #[Test]
    public function varintEncodesTypicalUnixNanos(): void
    {
        $writer = new ProtobufWriter();
        // A typical Unix timestamp in nanoseconds: ~1.7e18
        $nanos = 1_700_000_000_000_000_000;
        $writer->writeVarint($nanos);

        $bytes = $writer->toBytes();

        // Verify by decoding: read LEB128 back
        $decoded = self::decodeLeb128($bytes);
        self::assertSame($nanos, $decoded);
    }

    #[Test]
    public function varintEncodesPhpIntMax(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarint(PHP_INT_MAX);

        $bytes = $writer->toBytes();

        // PHP_INT_MAX = 2^63-1, requires 9 bytes in LEB128 (63 bits / 7 = 9)
        self::assertSame(9, strlen($bytes));

        $decoded = self::decodeLeb128($bytes);
        self::assertSame(PHP_INT_MAX, $decoded);
    }

    #[Test]
    public function varintFieldSkipsZeroValue(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarintField(1, 0);

        // Zero-valued varint fields are omitted per protobuf convention
        self::assertSame('', $writer->toBytes());
    }

    #[Test]
    public function varintFieldWritesTagAndValue(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarintField(1, 150);

        // Tag: (1 << 3) | 0 = 0x08, Value: 150 = 0x96 0x01
        self::assertSame("\x08\x96\x01", $writer->toBytes());
    }

    #[Test]
    public function boolFieldWritesTrueAsVarintOne(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeBoolField(3, true);

        // Tag: (3 << 3) | 0 = 0x18, Value: 1 = 0x01
        self::assertSame("\x18\x01", $writer->toBytes());
    }

    #[Test]
    public function boolFieldSkipsFalse(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeBoolField(3, false);

        self::assertSame('', $writer->toBytes());
    }

    #[Test]
    public function fixed64FieldWritesLittleEndian(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeFixed64Field(1, 1);

        // Tag: (1 << 3) | 1 = 0x09
        // Value: 1 as LE 64-bit = 01 00 00 00 00 00 00 00
        self::assertSame(
            '0901000000' . '00000000',
            bin2hex($writer->toBytes()),
        );
    }

    #[Test]
    public function fixed64FieldWritesLargeNanosCorrectly(): void
    {
        $writer = new ProtobufWriter();
        $nanos = 1_700_000_000_000_000_000;
        $writer->writeFixed64Field(7, $nanos);

        $bytes = $writer->toBytes();

        // Tag byte + 8 bytes fixed64
        self::assertSame(9, strlen($bytes));

        // Decode the fixed64: skip tag byte, read 8 bytes LE
        $lowArr = unpack('V', substr($bytes, 1, 4));
        $highArr = unpack('V', substr($bytes, 5, 4));
        self::assertIsArray($lowArr);
        self::assertIsArray($highArr);
        /** @var int $low */
        $low = $lowArr[1];
        /** @var int $high */
        $high = $highArr[1];
        $decoded = ($high << 32) | $low;

        self::assertSame($nanos, $decoded);
    }

    #[Test]
    public function doubleFieldWritesIeee754(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeDoubleField(4, 3.14);

        $bytes = $writer->toBytes();

        // Tag: (4 << 3) | 1 = 0x21
        self::assertSame(0x21, ord($bytes[0]));

        // Decode the double: skip tag byte, read 8 bytes LE
        $decodedArr = unpack('e', substr($bytes, 1, 8));
        self::assertIsArray($decodedArr);
        /** @var float $decoded */
        $decoded = $decodedArr[1];
        self::assertSame(3.14, $decoded);
    }

    #[Test]
    public function lengthDelimitedFieldWritesPrefixedData(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeLengthDelimitedField(2, 'testing');

        // Tag: (2 << 3) | 2 = 0x12
        // Length: 7 = 0x07
        // Data: "testing"
        self::assertSame("\x12\x07testing", $writer->toBytes());
    }

    #[Test]
    public function lengthDelimitedFieldSkipsEmptyData(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeLengthDelimitedField(2, '');

        self::assertSame('', $writer->toBytes());
    }

    #[Test]
    public function stringFieldAlwaysEmitsEvenIfEmpty(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeStringField(5, '');

        // Tag: (5 << 3) | 2 = 0x2A, Length: 0 = 0x00
        self::assertSame("\x2A\x00", $writer->toBytes());
    }

    #[Test]
    public function embeddedMessageWritesSubWriterBytes(): void
    {
        $sub = new ProtobufWriter();
        $sub->writeVarintField(1, 42);

        $parent = new ProtobufWriter();
        $parent->writeEmbeddedMessage(2, $sub);

        // Sub message: tag(1,varint)=0x08 + value(42)=0x2A → 2 bytes
        // Parent: tag(2,ld)=0x12 + length(2)=0x02 + sub bytes
        self::assertSame("\x12\x02\x08\x2A", $parent->toBytes());
    }

    #[Test]
    public function embeddedMessageSkipsEmptySubWriter(): void
    {
        $sub = new ProtobufWriter();
        $parent = new ProtobufWriter();
        $parent->writeEmbeddedMessage(2, $sub);

        self::assertSame('', $parent->toBytes());
    }

    #[Test]
    public function resetClearsBuffer(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarint(42);

        self::assertNotSame('', $writer->toBytes());

        $writer->reset();

        self::assertSame('', $writer->toBytes());
    }

    #[Test]
    public function writeRawBytesAppendsDirectly(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeRawBytes("\xDE\xAD");
        $writer->writeRawBytes("\xBE\xEF");

        self::assertSame("\xDE\xAD\xBE\xEF", $writer->toBytes());
    }

    #[Test]
    public function writeTagEncodesCorrectly(): void
    {
        $writer = new ProtobufWriter();

        // Field 15, wire type 2 (length-delimited) = (15 << 3) | 2 = 122 = 0x7A
        $writer->writeTag(15, 2);

        self::assertSame("\x7A", $writer->toBytes());
    }

    #[Test]
    public function writeTagWithHighFieldNumber(): void
    {
        $writer = new ProtobufWriter();

        // Field 16, wire type 0 (varint) = (16 << 3) | 0 = 128 → LEB128: 0x80 0x01
        $writer->writeTag(16, 0);

        self::assertSame("\x80\x01", $writer->toBytes());
    }

    #[Test]
    public function multipleFieldsAppendSequentially(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarintField(1, 1);
        $writer->writeVarintField(2, 2);

        // Field 1: 0x08 0x01, Field 2: 0x10 0x02
        self::assertSame("\x08\x01\x10\x02", $writer->toBytes());
    }

    /**
     * Decode a LEB128 unsigned varint from a binary string.
     */
    private static function decodeLeb128(string $bytes): int
    {
        $result = 0;
        $shift = 0;

        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $byte = ord($bytes[$i]);
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;

            if (($byte & 0x80) === 0) {
                break;
            }
        }

        return $result;
    }
}
