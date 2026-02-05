<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Protobuf;

use Pulsar\Api\Internal;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Encodes key-value attribute pairs into protobuf KeyValue messages.
 *
 * Shared by all three request builders to avoid duplication.
 */
#[Internal(reason: 'Shared attribute encoding logic for OTLP builders')]
final class AttributeEncoder
{
    /**
     * Write a KeyValue message as a repeated field.
     *
     * @param array<string, scalar> $attributes
     */
    public static function encode(ProtobufWriter $writer, int $fieldNumber, array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $kvWriter = new ProtobufWriter();

            // KeyValue.key = 1
            $kvWriter->writeStringField(OtlpFieldNumbers::KV_KEY, $key);

            // KeyValue.value = 2 (AnyValue embedded message)
            $anyValueWriter = new ProtobufWriter();
            self::encodeAnyValue($anyValueWriter, $value);
            $kvWriter->writeEmbeddedMessage(OtlpFieldNumbers::KV_VALUE, $anyValueWriter);

            $writer->writeEmbeddedMessage($fieldNumber, $kvWriter);
        }
    }

    /**
     * Encode a scalar value into an AnyValue message.
     */
    private static function encodeAnyValue(ProtobufWriter $writer, string|int|float|bool $value): void
    {
        if (is_string($value)) {
            $writer->writeStringField(OtlpFieldNumbers::AV_STRING, $value);
        } elseif (is_int($value)) {
            $writer->writeTag(OtlpFieldNumbers::AV_INT, 0);
            $writer->writeVarint($value);
        } elseif (is_float($value)) {
            $writer->writeDoubleField(OtlpFieldNumbers::AV_DOUBLE, $value);
        } elseif (is_bool($value)) {
            if ($value) {
                $writer->writeBoolField(OtlpFieldNumbers::AV_BOOL, true);
            } else {
                // writeBoolField skips false (proto3 default elision), but AnyValue
                // bool must explicitly encode false to distinguish from "not set"
                $writer->writeTag(OtlpFieldNumbers::AV_BOOL, 0);
                $writer->writeVarint(0);
            }
        }
    }
}
