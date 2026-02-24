<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Evidence\ChainLink;
use Pulsar\Extension\Studio\Console\Evidence\HashChain;

use function hash;
use function strlen;

#[CoversClass(HashChain::class)]
#[CoversClass(ChainLink::class)]
final class HashChainTest extends TestCase
{
    #[Test]
    public function seedHashReturnsDeterministicValue(): void
    {
        $seed1 = HashChain::seedHash();
        $seed2 = HashChain::seedHash();

        self::assertSame($seed1, $seed2);
        self::assertSame(64, strlen($seed1));
    }

    #[Test]
    public function seedHashIsSha256OfSeedInput(): void
    {
        $expected = hash('sha256', 'PULSAR_STUDIO_CHAIN_SEED');

        self::assertSame($expected, HashChain::seedHash());
    }

    #[Test]
    public function computeLinkCreatesChainLink(): void
    {
        $chain = new HashChain();
        $envelope = $this->createEnvelope('evt-1');

        $link = $chain->computeLink($envelope, HashChain::seedHash());

        self::assertInstanceOf(ChainLink::class, $link);
        self::assertSame('evt-1', $link->eventId);
        self::assertSame(HashChain::seedHash(), $link->previousHash);
        self::assertSame(64, strlen($link->currentHash));
        self::assertNull($link->linkMac);
    }

    #[Test]
    public function computeLinkHashIsDeterministic(): void
    {
        $chain = new HashChain();
        $envelope = $this->createEnvelope('evt-1');
        $previousHash = HashChain::seedHash();

        $link1 = $chain->computeLink($envelope, $previousHash);
        $link2 = $chain->computeLink($envelope, $previousHash);

        self::assertSame($link1->currentHash, $link2->currentHash);
    }

    #[Test]
    public function verifyLinkHashReturnsTrueForValidLink(): void
    {
        $chain = new HashChain();
        $envelope = $this->createEnvelope('evt-1');
        $previousHash = HashChain::seedHash();

        $link = $chain->computeLink($envelope, $previousHash);

        self::assertTrue(
            HashChain::verifyLinkHash($previousHash, $envelope->canonical(), $link->currentHash),
        );
    }

    #[Test]
    public function verifyLinkHashReturnsFalseForTamperedHash(): void
    {
        self::assertFalse(
            HashChain::verifyLinkHash('prev', 'canonical', 'invalid_hash'),
        );
    }

    #[Test]
    public function hasMacKeyReturnsFalseWithoutKey(): void
    {
        $chain = new HashChain();

        self::assertFalse($chain->hasMacKey());
    }

    #[Test]
    public function chainLinkConstructorSetsFields(): void
    {
        $link = new ChainLink(
            eventId: 'evt-1',
            previousHash: 'prev-hash',
            currentHash: 'curr-hash',
            linkMac: 'mac-value',
        );

        self::assertSame('evt-1', $link->eventId);
        self::assertSame('prev-hash', $link->previousHash);
        self::assertSame('curr-hash', $link->currentHash);
        self::assertSame('mac-value', $link->linkMac);
    }

    private function createEnvelope(string $eventId): EventEnvelope
    {
        return new EventEnvelope(
            eventId: $eventId,
            eventType: EventType::LogEntry,
            schemaVersion: EventVersion::V1,
            timestampUs: 1700000000000000,
            requestId: null,
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'local',
            hostname: 'test',
            payload: ['message' => 'test'],
            payloadHash: hash('sha256', '{"message":"test"}'),
        );
    }
}
