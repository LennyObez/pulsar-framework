<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\SessionId;
use Pulsar\Extension\Analytics\Domain\VisitorId;

final class SessionIdTest extends TestCase
{
    #[Test]
    public function generateProducesDeterministicHash(): void
    {
        $key = sodium_crypto_generichash_keygen();
        $visitorId = VisitorId::generate('127.0.0.1', 'Mozilla/5.0', $key, 20000);

        $sid1 = SessionId::generate($visitorId, 1709290800, $key);
        $sid2 = SessionId::generate($visitorId, 1709290800, $key);

        self::assertSame($sid1->hash, $sid2->hash);
    }

    #[Test]
    public function differentTimestampBucketsProduceDifferentHashes(): void
    {
        $key = sodium_crypto_generichash_keygen();
        $visitorId = VisitorId::generate('127.0.0.1', 'Mozilla/5.0', $key, 20000);

        $sid1 = SessionId::generate($visitorId, 1709290800, $key);
        $sid2 = SessionId::generate($visitorId, 1709294400, $key);

        self::assertNotSame($sid1->hash, $sid2->hash);
    }

    #[Test]
    public function fromHashReconstructsSessionId(): void
    {
        $sid = SessionId::fromHash('abc123def456');

        self::assertSame('abc123def456', $sid->hash);
    }

    #[Test]
    public function toStringReturnsHash(): void
    {
        $sid = SessionId::fromHash('test-hash');

        self::assertSame('test-hash', $sid->toString());
    }

    #[Test]
    public function equalsComparesHashes(): void
    {
        $a = SessionId::fromHash('same-hash');
        $b = SessionId::fromHash('same-hash');
        $c = SessionId::fromHash('different-hash');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
