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
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function random_bytes;
use function str_repeat;
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
        $keyProvider = $this->masterKey();

        $seed1 = HashChain::deriveSeedFromMasterKey($keyProvider);
        $seed2 = HashChain::deriveSeedFromMasterKey($keyProvider);

        self::assertSame($seed1, $seed2);
        // Hex-encoded 32 bytes = 64 hex chars
        self::assertSame(64, strlen($seed1));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $seed1);
    }

    #[Test]
    public function deriveSeedFromMasterKeyDiffersPerKey(): void
    {
        $seed1 = HashChain::deriveSeedFromMasterKey($this->masterKey());
        $seed2 = HashChain::deriveSeedFromMasterKey($this->masterKey());

        self::assertNotSame($seed1, $seed2);
    }

    /**
     * Routing the derivation through {@see MasterKey} moved the call out of this
     * class but must not have moved the bytes: a Studio instance whose
     * `studio_meta('chain_seed')` was written by the previous implementation has
     * to keep verifying against the same anchor, or its whole evidence chain
     * reads as broken after an upgrade.
     *
     * The vector below is the value the direct
     * `sodium_crypto_kdf_derive_from_key(32, 15, 'stu_seed', $raw)` call produced
     * for this key, computed against the shipped implementation before the change.
     */
    #[Test]
    public function deriveSeedFromMasterKeyStillProducesTheSeedTheDirectCallProduced(): void
    {
        $masterKey = MasterKey::fromHex(str_repeat('2b', SODIUM_CRYPTO_KDF_KEYBYTES));

        self::assertSame(
            'e333758c3e221b2e7f0ea5a7a8f599e9d55ab8c675dc2d0e2d6fac3dd6b5e016',
            HashChain::deriveSeedFromMasterKey($masterKey),
        );
    }

    #[Test]
    public function seedHashWithDerivedSeedDiffersFromFallback(): void
    {
        $derivedSeed = HashChain::deriveSeedFromMasterKey($this->masterKey());

        $fallbackHash = HashChain::seedHash();
        $derivedHash = HashChain::seedHash($derivedSeed);

        self::assertNotSame($fallbackHash, $derivedHash);
        self::assertSame(64, strlen($derivedHash));
    }

    #[Test]
    public function seedHashWithDerivedSeedIsDeterministic(): void
    {
        $derivedSeed = HashChain::deriveSeedFromMasterKey($this->masterKey());

        $hash1 = HashChain::seedHash($derivedSeed);
        $hash2 = HashChain::seedHash($derivedSeed);

        self::assertSame($hash1, $hash2);
    }

    /**
     * A master key over fresh random bytes, so two calls hold two different keys.
     */
    private function masterKey(): MasterKey
    {
        return MasterKey::fromHex(bin2hex(random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES)));
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
