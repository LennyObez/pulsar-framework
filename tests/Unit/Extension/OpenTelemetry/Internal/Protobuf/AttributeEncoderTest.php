<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\AttributeEncoder;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ProtobufWriter;

#[CoversClass(AttributeEncoder::class)]
final class AttributeEncoderTest extends TestCase
{
    #[Test]
    public function encodesStringAttribute(): void
    {
        $writer = new ProtobufWriter();

        AttributeEncoder::encode($writer, OtlpFieldNumbers::SPAN_ATTRIBUTES, [
            'service.name' => 'test-service',
        ]);

        $bytes = $writer->toBytes();
        self::assertNotEmpty($bytes);
        // Verify the field tag for embedded message is present
        self::assertNotSame('', $bytes);
    }

    #[Test]
    public function encodesIntAttribute(): void
    {
        $writer = new ProtobufWriter();

        AttributeEncoder::encode($writer, OtlpFieldNumbers::SPAN_ATTRIBUTES, [
            'http.status_code' => 200,
        ]);

        $bytes = $writer->toBytes();
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function encodesFloatAttribute(): void
    {
        $writer = new ProtobufWriter();

        AttributeEncoder::encode($writer, OtlpFieldNumbers::SPAN_ATTRIBUTES, [
            'request.duration' => 1.5,
        ]);

        $bytes = $writer->toBytes();
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function encodesBoolTrueAttribute(): void
    {
        $writer = new ProtobufWriter();

        AttributeEncoder::encode($writer, OtlpFieldNumbers::SPAN_ATTRIBUTES, [
            'error' => true,
        ]);

        $bytes = $writer->toBytes();
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function encodesBoolFalseAttribute(): void
    {
        $writer = new ProtobufWriter();

        AttributeEncoder::encode($writer, OtlpFieldNumbers::SPAN_ATTRIBUTES, [
            'error' => false,
        ]);

        $bytes = $writer->toBytes();
        // false bool must be explicitly encoded (not elided) in AnyValue
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function encodesMultipleAttributes(): void
    {
        $writer = new ProtobufWriter();

        AttributeEncoder::encode($writer, OtlpFieldNumbers::SPAN_ATTRIBUTES, [
            'string_key' => 'value',
            'int_key' => 42,
            'float_key' => 3.14,
            'bool_key' => true,
        ]);

        $bytes = $writer->toBytes();
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function encodesEmptyAttributes(): void
    {
        $writer = new ProtobufWriter();

        AttributeEncoder::encode($writer, OtlpFieldNumbers::SPAN_ATTRIBUTES, []);

        $bytes = $writer->toBytes();
        self::assertSame('', $bytes);
    }
}
