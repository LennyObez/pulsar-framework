<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate\Asn1;

use Pulsar\Api\Internal;
use RuntimeException;

use function count;
use function implode;
use function intdiv;
use function ord;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Minimal, strict ASN.1 DER decoder for X.509 / eIDAS certificate parsing.
 *
 * Decodes the definite-length DER subset the certificate, QcStatements (ETSI
 * TS 119 495), OCSP (RFC 6960) and CRL (RFC 5280) grammars need: identifier and
 * length octets (short and long form), constructed vs primitive nodes, and OID
 * content decoding. Indefinite lengths (not valid in DER) and truncated input
 * are rejected. Nesting depth is bounded to guard against a hostile certificate.
 */
#[Internal(reason: 'ASN.1 DER decoding for PSD2/eIDAS certificate parsing')]
final class DerDecoder
{
    private const int MAX_DEPTH = 32;

    private int $offset = 0;

    private function __construct(private readonly string $data) {}

    /**
     * Decode a single top-level DER element, requiring the whole input to be
     * consumed.
     */
    public static function decode(string $der): DerNode
    {
        $decoder = new self($der);
        $node = $decoder->decodeNode(0);

        if ($decoder->offset !== strlen($der)) {
            throw new RuntimeException('DER: trailing bytes after top-level element');
        }

        return $node;
    }

    private function decodeNode(int $depth): DerNode
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('DER: maximum nesting depth exceeded');
        }

        $start = $this->offset;

        $identifier = $this->readByte();
        $tagClass = ($identifier >> 6) & 0x03;
        $constructed = ($identifier & 0x20) !== 0;
        $tagNumber = $identifier & 0x1F;

        if ($tagNumber === 0x1F) {
            $tagNumber = $this->readHighTagNumber();
        }

        $length = $this->readLength();
        $content = $this->readBytes($length);
        $raw = substr($this->data, $start, $this->offset - $start);

        if (!$constructed) {
            return new DerNode($tagClass, false, $tagNumber, $content, [], $raw);
        }

        $children = self::decodeChildren($content, $depth + 1);

        return new DerNode($tagClass, true, $tagNumber, $content, $children, $raw);
    }

    /**
     * @return list<DerNode>
     */
    private static function decodeChildren(string $content, int $depth): array
    {
        $inner = new self($content);
        $children = [];

        while ($inner->offset < strlen($content)) {
            $children[] = $inner->decodeNode($depth);
        }

        return $children;
    }

    private function readHighTagNumber(): int
    {
        $value = 0;

        do {
            $byte = $this->readByte();
            $value = ($value << 7) | ($byte & 0x7F);

            if ($value > 0xFFFFFF) {
                throw new RuntimeException('DER: tag number too large');
            }
        } while (($byte & 0x80) !== 0);

        return $value;
    }

    private function readLength(): int
    {
        $first = $this->readByte();

        if ($first < 0x80) {
            return $first;
        }

        if ($first === 0x80) {
            throw new RuntimeException('DER: indefinite length is not valid');
        }

        $numBytes = $first & 0x7F;

        if ($numBytes > 4) {
            throw new RuntimeException('DER: length field too large');
        }

        $length = 0;

        for ($i = 0; $i < $numBytes; $i++) {
            $length = ($length << 8) | $this->readByte();
        }

        return $length;
    }

    private function readByte(): int
    {
        if ($this->offset >= strlen($this->data)) {
            throw new RuntimeException('DER: unexpected end of data');
        }

        return ord($this->data[$this->offset++]);
    }

    private function readBytes(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw new RuntimeException('DER: content length exceeds available data');
        }

        $bytes = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }

    /**
     * Decode the content of an OBJECT IDENTIFIER node into its dotted-decimal
     * string form.
     */
    public static function oidToString(string $content): string
    {
        if ($content === '') {
            throw new RuntimeException('DER: empty OID');
        }

        $bytes = [];
        for ($i = 0, $n = strlen($content); $i < $n; $i++) {
            $bytes[] = ord($content[$i]);
        }

        $first = $bytes[0];
        $parts = [(string) intdiv($first, 40), (string) ($first % 40)];

        $value = 0;
        for ($i = 1, $n = count($bytes); $i < $n; $i++) {
            $value = ($value << 7) | ($bytes[$i] & 0x7F);

            if (($bytes[$i] & 0x80) === 0) {
                $parts[] = (string) $value;
                $value = 0;
            }
        }

        return implode('.', $parts);
    }

    /**
     * Human-readable dump for diagnostics/tests.
     */
    public static function describe(DerNode $node, int $indent = 0): string
    {
        $line = sprintf('%sclass=%d constructed=%d tag=%d len=%d', str_repeat('  ', $indent), $node->tagClass, $node->constructed ? 1 : 0, $node->tagNumber, strlen($node->content));
        $lines = [$line];

        foreach ($node->children as $child) {
            $lines[] = self::describe($child, $indent + 1);
        }

        return implode("\n", $lines);
    }
}
