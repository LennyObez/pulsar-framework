<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\AttributeEncoder;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ProtobufWriter;

#[CoversClass(AttributeEncoder::class)]
final class AttributeEncoderTest extends TestCase
{
    #[Test]
    public function encodesStringAttribute(): void
    {
        $writer = new ProtobufWriter();
        AttributeEncoder::encode($writer, 1, ['key' => 'value']);

        $bytes = $writer->toBytes();
        self::assertNotSame('', $bytes);
        // The encoded bytes should contain the key and value strings
        self::assertStringContainsString('key', $bytes);
        self::assertStringContainsString('value', $bytes);
    }

    #[Test]
    public function encodesIntAttribute(): void
    {
        $writer = new ProtobufWriter();
        AttributeEncoder::encode($writer, 1, ['count' => 42]);

        $bytes = $writer->toBytes();
        self::assertNotSame('', $bytes);
        self::assertStringContainsString('count', $bytes);
    }

    #[Test]
    public function encodesFloatAttribute(): void
    {
        $writer = new ProtobufWriter();
        AttributeEncoder::encode($writer, 1, ['ratio' => 0.75]);

        $bytes = $writer->toBytes();
        self::assertNotSame('', $bytes);
        self::assertStringContainsString('ratio', $bytes);
    }

    #[Test]
    public function encodesBoolTrueAttribute(): void
    {
        $writer = new ProtobufWriter();
        AttributeEncoder::encode($writer, 1, ['active' => true]);

        $bytes = $writer->toBytes();
        self::assertNotSame('', $bytes);
        self::assertStringContainsString('active', $bytes);
    }

    #[Test]
    public function encodesBoolFalseAttribute(): void
    {
        $writer = new ProtobufWriter();
        AttributeEncoder::encode($writer, 1, ['disabled' => false]);

        $bytes = $writer->toBytes();
        self::assertNotSame('', $bytes);
        // false must be explicitly encoded (not elided) for AnyValue
        self::assertStringContainsString('disabled', $bytes);
    }

    #[Test]
    public function encodesMultipleAttributes(): void
    {
        $writer = new ProtobufWriter();
        AttributeEncoder::encode($writer, 1, [
            'str' => 'hello',
            'num' => 123,
            'flag' => true,
        ]);

        $bytes = $writer->toBytes();
        self::assertStringContainsString('str', $bytes);
        self::assertStringContainsString('hello', $bytes);
        self::assertStringContainsString('num', $bytes);
        self::assertStringContainsString('flag', $bytes);
    }

    #[Test]
    public function encodesEmptyAttributes(): void
    {
        $writer = new ProtobufWriter();
        AttributeEncoder::encode($writer, 1, []);

        self::assertSame('', $writer->toBytes());
    }
}
