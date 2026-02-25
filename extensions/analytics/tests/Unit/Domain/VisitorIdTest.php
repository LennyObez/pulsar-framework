<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\VisitorId;

final class VisitorIdTest extends TestCase
{
    #[Test]
    public function generateProducesDeterministicHash(): void
    {
        $key = sodium_crypto_generichash_keygen();

        $v1 = VisitorId::generate('192.168.1.1', 'Mozilla/5.0', $key, 20000);
        $v2 = VisitorId::generate('192.168.1.1', 'Mozilla/5.0', $key, 20000);

        self::assertSame($v1->hash, $v2->hash);
    }

    #[Test]
    public function differentIpProducesDifferentHash(): void
    {
        $key = sodium_crypto_generichash_keygen();

        $v1 = VisitorId::generate('192.168.1.1', 'Mozilla/5.0', $key, 20000);
        $v2 = VisitorId::generate('10.0.0.1', 'Mozilla/5.0', $key, 20000);

        self::assertNotSame($v1->hash, $v2->hash);
    }

    #[Test]
    public function differentDayNumberProducesDifferentHash(): void
    {
        $key = sodium_crypto_generichash_keygen();

        $v1 = VisitorId::generate('192.168.1.1', 'Mozilla/5.0', $key, 20000);
        $v2 = VisitorId::generate('192.168.1.1', 'Mozilla/5.0', $key, 20001);

        self::assertNotSame($v1->hash, $v2->hash);
    }

    #[Test]
    public function differentUserAgentProducesDifferentHash(): void
    {
        $key = sodium_crypto_generichash_keygen();

        $v1 = VisitorId::generate('192.168.1.1', 'Mozilla/5.0', $key, 20000);
        $v2 = VisitorId::generate('192.168.1.1', 'Chrome/120', $key, 20000);

        self::assertNotSame($v1->hash, $v2->hash);
    }

    #[Test]
    public function fromHashReconstructsVisitorId(): void
    {
        $vid = VisitorId::fromHash('deadbeef1234');

        self::assertSame('deadbeef1234', $vid->hash);
    }

    #[Test]
    public function toStringReturnsHash(): void
    {
        $vid = VisitorId::fromHash('cafe0123');

        self::assertSame('cafe0123', $vid->toString());
    }

    #[Test]
    public function equalsComparesHashes(): void
    {
        $a = VisitorId::fromHash('same');
        $b = VisitorId::fromHash('same');
        $c = VisitorId::fromHash('different');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
