<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Evidence;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Evidence\HashChain;
use Pulsar\Observability\Context\CorrelationContext;

use function random_bytes;
use function strlen;

final class HashChainTest extends TestCase
{
    #[Test]
    public function seedHashIsConsistent(): void
    {
        $seed1 = HashChain::seedHash();
        $seed2 = HashChain::seedHash();

        self::assertSame($seed1, $seed2);
        self::assertSame(64, strlen($seed1));
    }

    #[Test]
    public function computeLinkProducesValidChainLink(): void
    {
        $chain = new HashChain();
        $envelope = $this->createEnvelope();
        $previousHash = HashChain::seedHash();

        $link = $chain->computeLink($envelope, $previousHash);

        self::assertSame($envelope->eventId, $link->eventId);
        self::assertSame($previousHash, $link->previousHash);
        self::assertSame(64, strlen($link->currentHash));
        self::assertNull($link->linkMac);
    }

    #[Test]
    public function computeLinkHashIsDeterministic(): void
    {
        $chain = new HashChain();
        $envelope = $this->createEnvelope();
        $previousHash = HashChain::seedHash();

        $link1 = $chain->computeLink($envelope, $previousHash);
        $link2 = $chain->computeLink($envelope, $previousHash);

        self::assertSame($link1->currentHash, $link2->currentHash);
    }

    #[Test]
    public function verifyLinkHashSucceedsForValidLink(): void
    {
        $chain = new HashChain();
        $envelope = $this->createEnvelope();
        $previousHash = HashChain::seedHash();

        $link = $chain->computeLink($envelope, $previousHash);

        $valid = HashChain::verifyLinkHash(
            $previousHash,
            $envelope->canonical(),
            $link->currentHash,
        );

        self::assertTrue($valid);
    }

    #[Test]
    public function verifyLinkHashFailsForTamperedHash(): void
    {
        $valid = HashChain::verifyLinkHash(
            'some_previous_hash',
            'some|canonical|data',
            'wrong_expected_hash',
        );

        self::assertFalse($valid);
    }

    #[Test]
    public function hasMacKeyReturnsFalseByDefault(): void
    {
        $chain = new HashChain();

        self::assertFalse($chain->hasMacKey());
    }

    #[Test]
    public function deriveSeedFromMasterKeyProducesDeterministicHex(): void
    {
        $masterKeyRaw = random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES);

        $seed1 = HashChain::deriveSeedFromMasterKey($masterKeyRaw);
        $seed2 = HashChain::deriveSeedFromMasterKey($masterKeyRaw);

        self::assertSame($seed1, $seed2);
        // Hex-encoded 32 bytes = 64 hex chars
        self::assertSame(64, strlen($seed1));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $seed1);
    }

    #[Test]
    public function deriveSeedFromMasterKeyDiffersPerKey(): void
    {
        $key1 = random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES);
        $key2 = random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES);

        $seed1 = HashChain::deriveSeedFromMasterKey($key1);
        $seed2 = HashChain::deriveSeedFromMasterKey($key2);

        self::assertNotSame($seed1, $seed2);
    }

    #[Test]
    public function seedHashWithDerivedSeedDiffersFromFallback(): void
    {
        $masterKeyRaw = random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES);
        $derivedSeed = HashChain::deriveSeedFromMasterKey($masterKeyRaw);

        $fallbackHash = HashChain::seedHash();
        $derivedHash = HashChain::seedHash($derivedSeed);

        self::assertNotSame($fallbackHash, $derivedHash);
        self::assertSame(64, strlen($derivedHash));
    }

    #[Test]
    public function seedHashWithDerivedSeedIsDeterministic(): void
    {
        $masterKeyRaw = random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES);
        $derivedSeed = HashChain::deriveSeedFromMasterKey($masterKeyRaw);

        $hash1 = HashChain::seedHash($derivedSeed);
        $hash2 = HashChain::seedHash($derivedSeed);

        self::assertSame($hash1, $hash2);
    }

    private function createEnvelope(): EventEnvelope
    {
        return EventEnvelope::wrap(
            new class implements ConsoleEvent {
                #[Override]
                public function eventType(): EventType
                {
                    return EventType::LogEntry;
                }

                #[Override]
                public function schemaVersion(): EventVersion
                {
                    return EventVersion::V1;
                }

                #[Override]
                public function toArray(): array
                {
                    return ['level' => 'info', 'message' => 'test'];
                }
            },
            new CorrelationContext(requestId: 'req-1'),
            'local',
            'test-host',
        );
    }
}
