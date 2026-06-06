<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Protobuf;

use Pulsar\Api\Internal;

use function chr;
use function pack;
use function strlen;

/**
 * Manual protobuf wire format encoder.
 *
 * Supports varint (wire type 0), fixed64 (wire type 1), and
 * length-delimited (wire type 2) encoding without any external
 * protobuf library dependency.
 */
#[Internal(reason: 'Low-level binary encoder for OTLP serialization')]
final class ProtobufWriter
{
    private string $buffer = '';

    /**
     * Write a field tag (field number + wire type).
     */
    public function writeTag(int $fieldNumber, int $wireType): void
    {
        $this->writeVarint(($fieldNumber << 3) | $wireType);
    }

    /**
     * Encode unsigned varint using LEB128.
     *
     * 64-bit safe: uses bitwise mask to avoid PHP signed right-shift issues.
     */
    public function writeVarint(int $value): void
    {
        // Handle zero explicitly
        if ($value === 0) {
            $this->buffer .= "\x00";
            return;
        }

        // For negative values (signed 64-bit), treat as unsigned 64-bit
        // PHP uses signed 64-bit integers, so we must handle the full range
        $remaining = $value;

        // Process 7 bits at a time using LEB128 encoding
        // Use a mask-based approach to avoid signed right-shift issues
        for ($i = 0; $i < 10; $i++) {
            $byte = $remaining & 0x7F;

            // Unsigned right shift by 7: mask off sign extension
            $remaining = ($remaining >> 7) & 0x01FFFFFFFFFFFFFF;

            if ($remaining === 0) {
                $this->buffer .= chr($byte);
                return;
            }

            // Set continuation bit
            $this->buffer .= chr($byte | 0x80);
        }
    }

    /**
     * Write a varint field (wire type 0).
     */
    public function writeVarintField(int $fieldNumber, int $value): void
    {
        if ($value === 0) {
            return;
        }

        $this->writeTag($fieldNumber, 0);
        $this->writeVarint($value);
    }

    /**
     * Write a bool field as varint (wire type 0).
     */
    public function writeBoolField(int $fieldNumber, bool $value): void
    {
        if (!$value) {
            return;
        }

        $this->writeTag($fieldNumber, 0);
        $this->writeVarint(1);
    }

    /**
     * Write a fixed64 field (wire type 1) as little-endian 64-bit integer.
     *
     * Uses explicit low/high 32-bit split to guarantee correct byte order
     * across platforms.
     */
    public function writeFixed64Field(int $fieldNumber, int $value): void
    {
        $this->writeTag($fieldNumber, 1);
        $low = $value & 0xFFFFFFFF;
        $high = ($value >> 32) & 0xFFFFFFFF;
        $this->buffer .= pack('V', $low) . pack('V', $high);
    }

    /**
     * Write a double field (wire type 1) as little-endian 64-bit IEEE 754.
     */
    public function writeDoubleField(int $fieldNumber, float $value): void
    {
        $this->writeTag($fieldNumber, 1);
        $this->buffer .= pack('e', $value);
    }

    /**
     * Write a length-delimited field (wire type 2): string, bytes, or embedded message.
     */
    public function writeLengthDelimitedField(int $fieldNumber, string $data): void
    {
        if ($data === '') {
            return;
        }

        $this->writeTag($fieldNumber, 2);
        $this->writeVarint(strlen($data));
        $this->buffer .= $data;
    }

    /**
     * Write a string field (wire type 2). Always emits even if empty,
     * unlike writeLengthDelimitedField which skips empty values.
     */
    public function writeStringField(int $fieldNumber, string $value): void
    {
        $this->writeTag($fieldNumber, 2);
        $this->writeVarint(strlen($value));
        $this->buffer .= $value;
    }

    /**
     * Write bytes from a sub-writer as an embedded message field.
     */
    public function writeEmbeddedMessage(int $fieldNumber, self $subWriter): void
    {
        $bytes = $subWriter->toBytes();

        if ($bytes === '') {
            return;
        }

        $this->writeTag($fieldNumber, 2);
        $this->writeVarint(strlen($bytes));
        $this->buffer .= $bytes;
    }

    /**
     * Append raw bytes directly to the buffer.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function writeRawBytes(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    /**
     * Reset the buffer, discarding all written data.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function reset(): void
    {
        $this->buffer = '';
    }

    /**
     * Get the accumulated binary output.
     */
    public function toBytes(): string
    {
        return $this->buffer;
    }
}
