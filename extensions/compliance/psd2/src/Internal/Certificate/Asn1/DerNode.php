<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate\Asn1;

use Pulsar\Api\Internal;

use function count;

/**
 * A single decoded ASN.1 DER TLV node.
 *
 * Primitive nodes carry raw content bytes; constructed nodes carry child nodes.
 * `tagClass` and `tagNumber` are the decoded identifier octet fields, so
 * context-specific tags (e.g. the [3] EXPLICIT extensions wrapper in an X.509
 * certificate) are addressable without guessing at universal tag numbers.
 */
#[Internal(reason: 'ASN.1 DER decoding for PSD2/eIDAS certificate parsing')]
final readonly class DerNode
{
    public const int CLASS_UNIVERSAL = 0;
    public const int CLASS_CONTEXT = 2;

    // Universal tag numbers used by the certificate/QcStatements grammar.
    public const int TAG_INTEGER = 0x02;
    public const int TAG_BIT_STRING = 0x03;
    public const int TAG_OCTET_STRING = 0x04;
    public const int TAG_OID = 0x06;
    public const int TAG_UTF8_STRING = 0x0C;
    public const int TAG_SEQUENCE = 0x10;
    public const int TAG_SET = 0x11;
    public const int TAG_PRINTABLE_STRING = 0x13;
    public const int TAG_IA5_STRING = 0x16;

    /**
     * @param int          $tagClass      Identifier class (0 universal, 2 context-specific, …)
     * @param bool         $constructed   Whether the node holds child nodes
     * @param int          $tagNumber     Tag number within the class
     * @param string       $content       Raw content bytes (primitive nodes)
     * @param list<DerNode> $children      Decoded children (constructed nodes)
     * @param string       $raw           The full TLV encoding of this node (identifier+length+content),
     *                                    so sub-structures (e.g. an issuer Name) can be re-hashed exactly.
     */
    public function __construct(
        public int $tagClass,
        public bool $constructed,
        public int $tagNumber,
        public string $content,
        public array $children = [],
        public string $raw = '',
    ) {}

    public function isSequence(): bool
    {
        return $this->tagClass === self::CLASS_UNIVERSAL && $this->tagNumber === self::TAG_SEQUENCE;
    }

    public function isContextTag(int $number): bool
    {
        return $this->tagClass === self::CLASS_CONTEXT && $this->tagNumber === $number;
    }

    public function child(int $index): ?self
    {
        return $this->children[$index] ?? null;
    }

    public function childCount(): int
    {
        return count($this->children);
    }
}
