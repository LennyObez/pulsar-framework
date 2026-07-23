<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\DeviceIdentity\Internal;

use Pulsar\Api\Internal;
use RuntimeException;

use function is_int;
use function is_string;
use function ord;
use function strlen;
use function substr;
use function unpack;

/**
 * Minimal CBOR decoder for WebAuthn COSE-key and attestation parsing.
 *
 * Supports the subset CBOR (RFC 8949) needs for WebAuthn: unsigned/negative
 * integers, byte strings, text strings, arrays, maps, and simple values.
 * Throws {@see RuntimeException} on malformed input so a caller can treat a
 * decode failure as a verification failure.
 *
 * Mirrors the auth extension's decoder; core cannot import an extension, so the
 * device-identity path carries its own copy.
 */
#[Internal(reason: 'WebAuthn attestation parsing for device identity')]
final class CborDecoder
{
    private int $offset = 0;

    private function __construct(private readonly string $data) {}

    public static function decode(string $data): mixed
    {
        return new self($data)->decodeItem();
    }

    private function decodeItem(): mixed
    {
        if ($this->offset >= strlen($this->data)) {
            throw new RuntimeException('CBOR: unexpected end of data');
        }

        $byte = ord($this->data[$this->offset]);
        $majorType = $byte >> 5;
        $additionalInfo = $byte & 0x1F;
        $this->offset++;

        return match ($majorType) {
            0 => $this->decodeUnsignedInt($additionalInfo),
            1 => -1 - $this->decodeUnsignedInt($additionalInfo),
            2, 3 => $this->readBytes($this->decodeUnsignedInt($additionalInfo)),
            4 => $this->decodeArray($additionalInfo),
            5 => $this->decodeMap($additionalInfo),
            7 => $this->decodeSimple($additionalInfo),
            default => throw new RuntimeException("CBOR: unsupported major type $majorType"),
        };
    }

    private function decodeUnsignedInt(int $additionalInfo): int
    {
        if ($additionalInfo < 24) {
            return $additionalInfo;
        }

        return match ($additionalInfo) {
            24 => $this->readUint(1, 'C'),
            25 => $this->readUint(2, 'n'),
            26 => $this->readUint(4, 'N'),
            27 => $this->readUint64(),
            default => throw new RuntimeException('CBOR: invalid additional info for integer'),
        };
    }

    /**
     * @return list<mixed>
     */
    private function decodeArray(int $additionalInfo): array
    {
        $count = $this->decodeUnsignedInt($additionalInfo);
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $result[] = $this->decodeItem();
        }

        return $result;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decodeMap(int $additionalInfo): array
    {
        $count = $this->decodeUnsignedInt($additionalInfo);
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            /** @var mixed $key */
            $key = $this->decodeItem();

            if (!is_int($key) && !is_string($key)) {
                throw new RuntimeException('CBOR: map key must be an integer or string');
            }

            // Direct assignment (not spread): the spread operator renumbers
            // integer keys, but COSE keys are frequently negative/positive ints.
            $result[$key] = $this->decodeItem();
        }

        return $result;
    }

    private function decodeSimple(int $additionalInfo): bool|null
    {
        return match ($additionalInfo) {
            20 => false,
            21 => true,
            22 => null,
            default => throw new RuntimeException("CBOR: unsupported simple value $additionalInfo"),
        };
    }

    private function readUint(int $length, string $format): int
    {
        $this->ensureAvailable($length);
        /** @var array{value: int} $unpacked */
        $unpacked = unpack($format . 'value', $this->data, $this->offset);
        $this->offset += $length;

        return $unpacked['value'];
    }

    private function readUint64(): int
    {
        $this->ensureAvailable(8);
        /** @var array{hi: int, lo: int} $unpacked */
        $unpacked = unpack('Nhi/Nlo', $this->data, $this->offset);
        $this->offset += 8;

        return ($unpacked['hi'] << 32) | $unpacked['lo'];
    }

    private function readBytes(int $length): string
    {
        $this->ensureAvailable($length);
        $bytes = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }

    private function ensureAvailable(int $length): void
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw new RuntimeException('CBOR: unexpected end of data');
        }
    }
}
