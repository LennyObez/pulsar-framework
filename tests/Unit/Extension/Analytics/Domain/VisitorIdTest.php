<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\VisitorId;

#[CoversClass(VisitorId::class)]
#[RequiresPhpExtension('sodium')]
final class VisitorIdTest extends TestCase
{
    #[Test]
    public function generateProducesDeterministicHash(): void
    {
        $key = sodium_crypto_generichash_keygen();

        $id1 = VisitorId::generate('192.168.1.1', 'Mozilla/5.0', $key, 20000);
        $id2 = VisitorId::generate('192.168.1.1', 'Mozilla/5.0', $key, 20000);

        self::assertSame($id1->hash, $id2->hash);
    }

    #[Test]
    public function generateDiffersForDifferentInputs(): void
    {
        $key = sodium_crypto_generichash_keygen();

        $id1 = VisitorId::generate('192.168.1.1', 'Mozilla/5.0', $key, 20000);
        $id2 = VisitorId::generate('192.168.1.2', 'Mozilla/5.0', $key, 20000);

        self::assertNotSame($id1->hash, $id2->hash);
    }

    #[Test]
    public function generateDiffersForDifferentDays(): void
    {
        $key = sodium_crypto_generichash_keygen();

        $id1 = VisitorId::generate('192.168.1.1', 'Mozilla/5.0', $key, 20000);
        $id2 = VisitorId::generate('192.168.1.1', 'Mozilla/5.0', $key, 20001);

        self::assertNotSame($id1->hash, $id2->hash);
    }

    #[Test]
    public function fromHashReconstructs(): void
    {
        $id = VisitorId::fromHash('abc123hex');

        self::assertSame('abc123hex', $id->hash);
    }

    #[Test]
    public function toStringReturnsHash(): void
    {
        $id = VisitorId::fromHash('test-hash');

        self::assertSame('test-hash', $id->toString());
    }

    #[Test]
    public function equalsComparesHashes(): void
    {
        $id1 = VisitorId::fromHash('same');
        $id2 = VisitorId::fromHash('same');
        $id3 = VisitorId::fromHash('different');

        self::assertTrue($id1->equals($id2));
        self::assertFalse($id1->equals($id3));
    }
}
