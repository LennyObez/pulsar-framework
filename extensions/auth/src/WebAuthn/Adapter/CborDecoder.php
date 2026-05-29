<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Adapter;

use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\WebAuthn\Exception\WebAuthnException;

use function ord;
use function strlen;

use const INF;

/**
 * Minimal CBOR decoder for WebAuthn attestation object parsing.
 *
 * Supports the subset of CBOR required by WebAuthn: maps, byte strings,
 * text strings, unsigned/negative integers, arrays, booleans, and null.
 */
#[Internal(reason: 'WebAuthn adapter internals')]
final class CborDecoder
{
    private int $offset = 0;

    private function __construct(private readonly string $data) {}

    /**
     * Decode a CBOR-encoded byte string.
     */
    public static function decode(string $data): mixed
    {
        $decoder = new self($data);
        return $decoder->decodeItem();
    }

    /**
     * @return int|string|float|bool|array<array-key, mixed>|null A decoded CBOR value
     */
    private function decodeItem(): mixed
    {
        if ($this->offset >= strlen($this->data)) {
            throw WebAuthnException::invalidAttestation('CBOR: unexpected end of data');
        }

        $byte = ord($this->data[$this->offset]);
        $majorType = $byte >> 5;
        $additionalInfo = $byte & 0x1F;
        $this->offset++;

        return match ($majorType) {
            0 => $this->decodeUnsignedInt($additionalInfo),
            1 => -1 - $this->decodeUnsignedInt($additionalInfo),
            2 => $this->decodeByteString($additionalInfo),
            3 => $this->decodeTextString($additionalInfo),
            4 => $this->decodeArray($additionalInfo),
            5 => $this->decodeMap($additionalInfo),
            7 => $this->decodeSimple($additionalInfo),
            default => throw WebAuthnException::invalidAttestation("CBOR: unsupported major type $majorType"),
        };
    }

    private function decodeUnsignedInt(int $additionalInfo): int
    {
        if ($additionalInfo < 24) {
            return $additionalInfo;
        }

        return match ($additionalInfo) {
            24 => $this->readUint8(),
            25 => $this->readUint16(),
            26 => $this->readUint32(),
            27 => $this->readUint64(),
            default => throw WebAuthnException::invalidAttestation('CBOR: invalid additional info for integer'),
        };
    }

    private function decodeByteString(int $additionalInfo): string
    {
        $length = $this->decodeUnsignedInt($additionalInfo);
        return $this->readBytes($length);
    }

    private function decodeTextString(int $additionalInfo): string
    {
        $length = $this->decodeUnsignedInt($additionalInfo);
        return $this->readBytes($length);
    }

    /**
     * @return list<mixed>
     */
    private function decodeArray(int $additionalInfo): array
    {
        $count = $this->decodeUnsignedInt($additionalInfo);
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $result = [...$result, $this->decodeItem()];
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
            /** @var int|string $key */
            $key = $this->decodeItem();
            // Direct assignment, not [...$result, $key => ...]: the spread
            // operator renumbers integer keys, so a CBOR map key of 1 would
            // become 0 on the next iteration's spread. CBOR (COSE) keys are
            // frequently negative/positive ints, so this must be preserved.
            $result[$key] = $this->decodeItem();
        }

        return $result;
    }

    private function decodeSimple(int $additionalInfo): bool|null|float
    {
        return match ($additionalInfo) {
            20 => false,
            21 => true,
            22 => null,
            25 => $this->decodeFloat16(),
            26 => $this->decodeFloat32(),
            27 => $this->decodeFloat64(),
            default => throw WebAuthnException::invalidAttestation("CBOR: unsupported simple value $additionalInfo"),
        };
    }

    private function readUint8(): int
    {
        $this->ensureAvailable(1);
        $value = ord($this->data[$this->offset]);
        $this->offset++;
        return $value;
    }

    private function readUint16(): int
    {
        $this->ensureAvailable(2);
        // F385.18: `unpack('n', ...)` returns a 1-indexed map
        // (no `value` key). Use the named-format prefix
        // `nvalue/` so the key is predictable instead of relying
        // on PHP's positional default.
        /** @var array{value: int} $unpacked */
        $unpacked = unpack('nvalue', $this->data, $this->offset);
        $this->offset += 2;
        return $unpacked['value'];
    }

    private function readUint32(): int
    {
        $this->ensureAvailable(4);
        // F385.18: same fix as readUint16 — named format
        // produces the `value` key the @var annotation
        // promises.
        /** @var array{value: int} $unpacked */
        $unpacked = unpack('Nvalue', $this->data, $this->offset);
        $this->offset += 4;
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

    private function decodeFloat16(): float
    {
        $this->ensureAvailable(2);
        /** @var array{value: int} $unpacked */
        $unpacked = unpack('n', $this->data, $this->offset);
        $this->offset += 2;

        $half = $unpacked['value'];
        $sign = ($half >> 15) & 1;
        $exponent = ($half >> 10) & 0x1F;
        $mantissa = $half & 0x3FF;

        if ($exponent === 0) {
            $value = (float) $mantissa * (2 ** -24);
        } elseif ($exponent === 31) {
            $value = $mantissa === 0 ? INF : fdiv(0.0, 0.0);
        } else {
            $value = ((float) ($mantissa + 1024)) * ((float) (2 ** ($exponent - 25)));
        }

        return $sign === 1 ? -$value : $value;
    }

    private function decodeFloat32(): float
    {
        $this->ensureAvailable(4);
        /** @var array{value: float} $unpacked */
        $unpacked = unpack('G', $this->data, $this->offset);
        $this->offset += 4;
        return $unpacked['value'];
    }

    private function decodeFloat64(): float
    {
        $this->ensureAvailable(8);
        /** @var array{value: float} $unpacked */
        $unpacked = unpack('E', $this->data, $this->offset);
        $this->offset += 8;
        return $unpacked['value'];
    }

    private function ensureAvailable(int $length): void
    {
        if ($this->offset + $length > strlen($this->data)) {
            throw WebAuthnException::invalidAttestation('CBOR: unexpected end of data');
        }
    }
}
