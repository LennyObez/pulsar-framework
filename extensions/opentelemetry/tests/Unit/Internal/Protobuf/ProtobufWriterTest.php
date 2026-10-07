<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ProtobufWriter;

use function strlen;

#[CoversClass(ProtobufWriter::class)]
final class ProtobufWriterTest extends TestCase
{
    #[Test]
    public function initialBufferIsEmpty(): void
    {
        $writer = new ProtobufWriter();

        self::assertSame('', $writer->toBytes());
    }

    #[Test]
    public function writeVarintZero(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarint(0);

        self::assertSame("\x00", $writer->toBytes());
    }

    #[Test]
    public function writeVarintSmallValue(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarint(1);

        self::assertSame("\x01", $writer->toBytes());
    }

    #[Test]
    public function writeVarintMultiByteValue(): void
    {
        $writer = new ProtobufWriter();
        // 300 = 0b100101100 = varint bytes: 0xAC 0x02
        $writer->writeVarint(300);

        self::assertSame("\xAC\x02", $writer->toBytes());
    }

    #[Test]
    public function writeTagFieldOneVarint(): void
    {
        $writer = new ProtobufWriter();
        // field 1, wire type 0 (varint) = (1 << 3) | 0 = 0x08
        $writer->writeTag(1, 0);

        self::assertSame("\x08", $writer->toBytes());
    }

    #[Test]
    public function writeTagFieldTwoLengthDelimited(): void
    {
        $writer = new ProtobufWriter();
        // field 2, wire type 2 = (2 << 3) | 2 = 0x12
        $writer->writeTag(2, 2);

        self::assertSame("\x12", $writer->toBytes());
    }

    #[Test]
    public function writeVarintFieldSkipsZero(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarintField(1, 0);

        // proto3 default elision: zero varint fields not written
        self::assertSame('', $writer->toBytes());
    }

    #[Test]
    public function writeVarintFieldNonZero(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeVarintField(1, 5);

        // tag(1, varint) + varint(5) = 0x08 0x05
        self::assertSame("\x08\x05", $writer->toBytes());
    }

    #[Test]
    public function writeBoolFieldFalseIsElided(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeBoolField(1, false);

        self::assertSame('', $writer->toBytes());
    }

    #[Test]
    public function writeBoolFieldTrue(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeBoolField(1, true);

        // tag(1, varint) + varint(1)
        self::assertSame("\x08\x01", $writer->toBytes());
    }

    #[Test]
    public function writeFixed64FieldZero(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeFixed64Field(1, 0);

        // tag(1, fixed64=wire type 1) = 0x09 + 8 zero bytes
        $expected = "\x09" . "\x00\x00\x00\x00\x00\x00\x00\x00";
        self::assertSame($expected, $writer->toBytes());
    }

    #[Test]
    public function writeFixed64FieldValue(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeFixed64Field(1, 1);

        // tag + little-endian 64-bit 1
        $expected = "\x09" . "\x01\x00\x00\x00\x00\x00\x00\x00";
        self::assertSame($expected, $writer->toBytes());
    }

    #[Test]
    public function writeDoubleField(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeDoubleField(1, 1.5);

        // tag(1, fixed64) = 0x09, then 8 bytes for IEEE 754 double
        $bytes = $writer->toBytes();
        self::assertSame(9, strlen($bytes)); // 1 byte tag + 8 bytes double
        self::assertSame("\x09", $bytes[0]);
    }

    #[Test]
    public function writeLengthDelimitedFieldEmptyIsElided(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeLengthDelimitedField(1, '');

        self::assertSame('', $writer->toBytes());
    }

    #[Test]
    public function writeLengthDelimitedFieldWithData(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeLengthDelimitedField(1, 'AB');

        // tag(1, ld=2) = 0x0A, length=2, "AB"
        self::assertSame("\x0A\x02AB", $writer->toBytes());
    }

    #[Test]
    public function writeStringFieldEmptyStringIsWritten(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeStringField(1, '');

        // Unlike writeLengthDelimitedField, writeStringField writes empty strings
        // tag(1, ld=2) = 0x0A, length=0
        self::assertSame("\x0A\x00", $writer->toBytes());
    }

    #[Test]
    public function writeStringFieldWithContent(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeStringField(1, 'hello');

        // tag + length(5) + "hello"
        self::assertSame("\x0A\x05hello", $writer->toBytes());
    }

    #[Test]
    public function writeEmbeddedMessageEmptyIsElided(): void
    {
        $writer = new ProtobufWriter();
        $sub = new ProtobufWriter();
        $writer->writeEmbeddedMessage(1, $sub);

        self::assertSame('', $writer->toBytes());
    }

    #[Test]
    public function writeEmbeddedMessageWithContent(): void
    {
        $writer = new ProtobufWriter();
        $sub = new ProtobufWriter();
        $sub->writeVarintField(1, 42);

        $writer->writeEmbeddedMessage(2, $sub);

        $bytes = $writer->toBytes();
        // Should have tag(2, ld) + length + sub content
        self::assertNotSame('', $bytes);
        self::assertStringContainsString($sub->toBytes(), $bytes);
    }

    #[Test]
    public function writeRawBytes(): void
    {
        $writer = new ProtobufWriter();
        $writer->writeRawBytes("\xFF\x00\x01");

        self::assertSame("\xFF\x00\x01", $writer->toBytes());
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
}
