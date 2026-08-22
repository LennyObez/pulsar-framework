<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate\Asn1;

use Pulsar\Api\Internal;

use function chr;
use function count;
use function explode;
use function implode;
use function strlen;

/**
 * Minimal ASN.1 DER encoder for building OCSP requests (RFC 6960).
 *
 * Emits the definite-length DER the OCSP request grammar needs: SEQUENCE,
 * OCTET STRING, INTEGER, OBJECT IDENTIFIER, NULL, AlgorithmIdentifier and
 * EXPLICIT context tags. Round-trips with {@see DerDecoder}.
 */
#[Internal(reason: 'ASN.1 DER encoding for OCSP requests')]
final class DerEncoder
{
    public static function sequence(string ...$elements): string
    {
        return self::tlv(0x30, implode('', $elements));
    }

    public static function octetString(string $content): string
    {
        return self::tlv(0x04, $content);
    }

    public static function oid(string $dotted): string
    {
        return self::tlv(0x06, self::encodeOidContent($dotted));
    }

    public static function null(): string
    {
        return "\x05\x00";
    }

    /**
     * Wrap already-canonical INTEGER value bytes (e.g. a certificate serial
     * number taken verbatim from its decoded node) as an INTEGER.
     */
    public static function integerFromBytes(string $valueBytes): string
    {
        if ($valueBytes === '') {
            $valueBytes = "\x00";
        }

        return self::tlv(0x02, $valueBytes);
    }

    /**
     * An [n] EXPLICIT context-specific constructed wrapper.
     */
    public static function explicit(int $tagNumber, string $content): string
    {
        return self::tlv(0xA0 | ($tagNumber & 0x1F), $content);
    }

    /**
     * AlgorithmIdentifier ::= SEQUENCE { algorithm OID, parameters NULL }.
     */
    public static function algorithmIdentifier(string $oid): string
    {
        return self::sequence(self::oid($oid), self::null());
    }

    public static function tlv(int $tag, string $content): string
    {
        return chr($tag & 0xFF) . self::length(strlen($content)) . $content;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length & 0xFF);
        }

        $bytes = '';
        $value = $length;

        while ($value > 0) {
            $bytes = chr($value & 0xFF) . $bytes;
            $value >>= 8;
        }

        return chr((0x80 | strlen($bytes)) & 0xFF) . $bytes;
    }

    private static function encodeOidContent(string $dotted): string
    {
        $arcs = [];
        foreach (explode('.', $dotted) as $arc) {
            $arcs[] = (int) $arc;
        }

        $bytes = chr((40 * $arcs[0] + $arcs[1]) & 0xFF);
        $count = count($arcs);

        for ($i = 2; $i < $count; $i++) {
            $bytes .= self::base128($arcs[$i]);
        }

        return $bytes;
    }

    private static function base128(int $value): string
    {
        $out = chr($value & 0x7F);
        $value >>= 7;

        while ($value > 0) {
            $out = chr(0x80 | ($value & 0x7F)) . $out;
            $value >>= 7;
        }

        return $out;
    }
}
