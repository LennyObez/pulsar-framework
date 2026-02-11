<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\SessionId;
use Pulsar\Extension\Analytics\Domain\VisitorId;

#[CoversClass(SessionId::class)]
#[RequiresPhpExtension('sodium')]
final class SessionIdTest extends TestCase
{
    #[Test]
    public function generateProducesDeterministicHash(): void
    {
        $key = sodium_crypto_generichash_keygen();
        $visitorId = VisitorId::fromHash('visitor-abc');

        $s1 = SessionId::generate($visitorId, 1000, $key);
        $s2 = SessionId::generate($visitorId, 1000, $key);

        self::assertSame($s1->hash, $s2->hash);
    }

    #[Test]
    public function generateDiffersForDifferentBuckets(): void
    {
        $key = sodium_crypto_generichash_keygen();
        $visitorId = VisitorId::fromHash('visitor-abc');

        $s1 = SessionId::generate($visitorId, 1000, $key);
        $s2 = SessionId::generate($visitorId, 2000, $key);

        self::assertNotSame($s1->hash, $s2->hash);
    }

    #[Test]
    public function fromHashReconstructs(): void
    {
        $sid = SessionId::fromHash('session-hash-123');

        self::assertSame('session-hash-123', $sid->hash);
    }

    #[Test]
    public function toStringReturnsHash(): void
    {
        $sid = SessionId::fromHash('test-sid');

        self::assertSame('test-sid', $sid->toString());
    }

    #[Test]
    public function equalsComparesHashes(): void
    {
        $s1 = SessionId::fromHash('same');
        $s2 = SessionId::fromHash('same');
        $s3 = SessionId::fromHash('different');

        self::assertTrue($s1->equals($s2));
        self::assertFalse($s1->equals($s3));
    }
}
