<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\WebAuthn\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\Adapter\CborDecoder;

use function sprintf;

/**
 * RFC 8949 §A — Examples of Encoded CBOR Data Items.
 *
 * The WebAuthn attestation pipeline depends on a correct CBOR
 * decode at its foundation layer; without conformance against
 * the spec's canonical vectors, a malformed-but-accepted
 * encoding can let an attestation pass that would have failed
 * in a reference implementation, or vice versa.
 *
 * This test imports the public vectors from RFC 8949 Appendix
 * A (https://www.rfc-editor.org/rfc/rfc8949#appendix-A) for the
 * subset of major types Pulsar's decoder supports:
 *
 *   - major type 0 (unsigned integers, including length-prefix
 *     variants 24/25/26/27);
 *   - major type 1 (negative integers);
 *   - major type 2 (byte strings);
 *   - major type 3 (text strings, ASCII and UTF-8);
 *   - major type 4 (arrays, nested);
 *   - major type 5 (maps, integer and text keys);
 *   - major type 7 (false, true, null, floats).
 *
 * Vectors are encoded as hex-string `$encoded` → expected PHP
 * value `$expected` pairs. A regression here means the WebAuthn
 * CBOR layer is reading bytes differently from the spec — fix
 * the decoder, never the vector.
 */
#[CoversClass(CborDecoder::class)]
final class CborDecoderRfc8949ConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function unsignedIntVectors(): iterable
    {
        // RFC 8949 §A: unsigned integer vectors.
        yield '0'                  => ['00', 0];
        yield '1'                  => ['01', 1];
        yield '10'                 => ['0a', 10];
        yield '23 (last 1-byte)'   => ['17', 23];
        yield '24 (uint8 boundary)' => ['1818', 24];
        yield '25'                 => ['1819', 25];
        yield '100'                => ['1864', 100];
        yield '1000 (uint16)'      => ['1903e8', 1000];
        yield '1000000 (uint32)'   => ['1a000f4240', 1000000];
        yield '1000000000000 (u64)' => ['1b000000e8d4a51000', 1000000000000];
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function negativeIntVectors(): iterable
    {
        // RFC 8949 §A: negative integer vectors. The decoder
        // computes `-1 - n` from the n-encoded unsigned int.
        yield '-1'   => ['20', -1];
        yield '-10' => ['29', -10];
        yield '-100' => ['3863', -100];
        yield '-1000' => ['3903e7', -1000];
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function byteStringVectors(): iterable
    {
        // RFC 8949 §A: byte string vectors.
        yield "h''"            => ['40', ''];
        yield "h'01020304'"    => ['4401020304', "\x01\x02\x03\x04"];
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function textStringVectors(): iterable
    {
        // RFC 8949 §A: text string vectors. The UTF-8 sequences
        // are part of the spec's regression set — they keep the
        // decoder honest about not splitting multi-byte
        // codepoints.
        yield '""'              => ['60', ''];
        yield '"a"'             => ['6161', 'a'];
        yield '"IETF"'          => ['6449455446', 'IETF'];
        yield '"\\"\\\\"'       => ['62225c', '"\\'];
        yield '"\\u00fc" (ü)'   => ['62c3bc', "\u{00fc}"];
        yield '"\\u6c34" (水)'  => ['63e6b0b4', "\u{6c34}"];
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function arrayVectors(): iterable
    {
        // RFC 8949 §A: array vectors.
        yield '[]'         => ['80', []];
        yield '[1, 2, 3]'  => ['83010203', [1, 2, 3]];
        // Nested: [1, [2, 3], [4, 5]]
        yield 'nested'     => ['8301820203820405', [1, [2, 3], [4, 5]]];
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function mapVectors(): iterable
    {
        // RFC 8949 §A: map vectors. Pulsar's decoder builds
        // PHP arrays from CBOR maps; integer keys remain
        // integers, text keys remain strings.
        yield '{}'              => ['a0', []];
        yield '{1: 2, 3: 4}'    => ['a201020304', [1 => 2, 3 => 4]];
        yield '{"a": 1, "b": [2, 3]}' => [
            'a26161016162820203',
            ['a' => 1, 'b' => [2, 3]],
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function simpleVectors(): iterable
    {
        // RFC 8949 §A: simple values (major type 7).
        yield 'false' => ['f4', false];
        yield 'true'  => ['f5', true];
        yield 'null'  => ['f6', null];
    }

    #[Test]
    #[DataProvider('unsignedIntVectors')]
    #[DataProvider('negativeIntVectors')]
    #[DataProvider('byteStringVectors')]
    #[DataProvider('textStringVectors')]
    #[DataProvider('arrayVectors')]
    #[DataProvider('mapVectors')]
    #[DataProvider('simpleVectors')]
    public function rfc8949ConformanceVector(string $encoded, mixed $expected): void
    {
        $bytes = hex2bin($encoded);
        self::assertNotFalse($bytes, "Vector hex '$encoded' must decode to bytes");

        $actual = CborDecoder::decode($bytes);
        self::assertSame(
            $expected,
            $actual,
            sprintf(
                'RFC 8949 vector "%s" must decode to %s, got %s',
                $encoded,
                var_export($expected, true),
                var_export($actual, true),
            ),
        );
    }
}
