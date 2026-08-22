<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Internal\Certificate\Asn1;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerDecoder;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerEncoder;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerNode;

use function bin2hex;
use function str_repeat;

#[CoversClass(DerEncoder::class)]
#[CoversClass(DerDecoder::class)]
#[CoversClass(DerNode::class)]
final class DerCodecTest extends TestCase
{
    #[Test]
    public function encodesAndDecodesAStructureAndCapturesRawTlv(): void
    {
        $der = DerEncoder::sequence(
            DerEncoder::oid('1.3.6.1.5.5.7.48.1.1'),
            DerEncoder::octetString("\x01\x02\x03"),
            DerEncoder::integerFromBytes("\x2A"),
            DerEncoder::null(),
        );

        $node = DerDecoder::decode($der);

        self::assertTrue($node->isSequence());
        self::assertSame(4, $node->childCount());
        self::assertSame('1.3.6.1.5.5.7.48.1.1', DerDecoder::oidToString($node->child(0)?->content ?? ''));
        self::assertSame("\x01\x02\x03", $node->child(1)?->content);
        self::assertSame("\x2A", $node->child(2)?->content);

        // Raw TLV is captured for the whole node and each child (needed to
        // re-hash sub-structures such as an issuer Name).
        self::assertSame($der, $node->raw);
        self::assertSame(DerEncoder::oid('1.3.6.1.5.5.7.48.1.1'), $node->child(0)?->raw);
    }

    #[Test]
    public function explicitContextTagRoundTrips(): void
    {
        $node = DerDecoder::decode(DerEncoder::explicit(2, DerEncoder::octetString('hi')));

        self::assertTrue($node->isContextTag(2));
        self::assertTrue($node->constructed);
        self::assertSame('hi', $node->child(0)?->content);
    }

    #[Test]
    public function oidEncodingMatchesKnownBytes(): void
    {
        // SHA-1 (1.3.14.3.2.26) -> 06 05 2B 0E 03 02 1A
        self::assertSame(
            bin2hex("\x06\x05\x2B\x0E\x03\x02\x1A"),
            bin2hex(DerEncoder::oid('1.3.14.3.2.26')),
        );
    }

    #[Test]
    public function longFormLengthRoundTrips(): void
    {
        $payload = str_repeat("\xAB", 300);
        $node = DerDecoder::decode(DerEncoder::octetString($payload));

        self::assertSame($payload, $node->content);
    }
}
