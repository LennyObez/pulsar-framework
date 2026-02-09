<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Evidence;

use function hash;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Evidence\ChainLink;
use Pulsar\Extension\Studio\Console\Evidence\HashChain;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\HmacService;

use function random_bytes;
use function strlen;

#[CoversClass(HashChain::class)]
final class HashChainTest extends TestCase
{
    private HmacInterface $hmac;
    private string $macKey;

    protected function setUp(): void
    {
        $this->hmac = new HmacService();
        // 32-byte key meets BLAKE2b minimum requirement
        $this->macKey = random_bytes(32);
    }

    #[Test]
    public function seedHashReturnsDeterministicValue(): void
    {
        $seed1 = HashChain::seedHash();
        $seed2 = HashChain::seedHash();

        self::assertSame($seed1, $seed2);
        self::assertSame(64, strlen($seed1));
    }

    #[Test]
    public function seedHashUsesCorrectAlgorithm(): void
    {
        $expected = hash('sha256', 'PULSAR_STUDIO_CHAIN_SEED');
        $actual = HashChain::seedHash();

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function computeLinkWithoutMacKey(): void
    {
        $chain = new HashChain();
        $envelope = $this->createTestEnvelope('event-001');
        $previousHash = HashChain::seedHash();

        $link = $chain->computeLink($envelope, $previousHash);

        self::assertInstanceOf(ChainLink::class, $link);
        self::assertSame('event-001', $link->eventId);
        self::assertSame($previousHash, $link->previousHash);
        self::assertSame(64, strlen($link->currentHash));
        self::assertNull($link->linkMac);
    }

    #[Test]
    public function computeLinkWithMacKey(): void
    {
        $chain = new HashChain($this->hmac, $this->macKey);
        $envelope = $this->createTestEnvelope('event-002');
        $previousHash = HashChain::seedHash();

        $link = $chain->computeLink($envelope, $previousHash);

        self::assertInstanceOf(ChainLink::class, $link);
        self::assertSame('event-002', $link->eventId);
        self::assertSame($previousHash, $link->previousHash);
        self::assertSame(64, strlen($link->currentHash));
        self::assertNotNull($link->linkMac);
        self::assertSame(64, strlen($link->linkMac));
    }

    #[Test]
    public function computeLinkProducesCorrectHash(): void
    {
        $chain = new HashChain();
        $envelope = $this->createTestEnvelope('test-event');
        $previousHash = 'previous-hash-value';

        $link = $chain->computeLink($envelope, $previousHash);

        $expectedHash = hash('sha256', $previousHash . '|' . $envelope->canonical());
        self::assertSame($expectedHash, $link->currentHash);
    }

    #[Test]
    public function computeLinkProducesCorrectMac(): void
    {
        $chain = new HashChain($this->hmac, $this->macKey);
        $envelope = $this->createTestEnvelope('mac-test-event');
        $previousHash = HashChain::seedHash();

        $link = $chain->computeLink($envelope, $previousHash);

        $expectedMac = Hmac::computeHex($link->currentHash, $this->macKey);
        self::assertSame($expectedMac, $link->linkMac);
    }

    #[Test]
    public function computeLinkChainsMaintainsIntegrity(): void
    {
        $chain = new HashChain();
        $previousHash = HashChain::seedHash();

        $links = [];
        for ($i = 0; $i < 5; $i++) {
            $envelope = $this->createTestEnvelope("event-{$i}");
            $link = $chain->computeLink($envelope, $previousHash);
            $links[] = $link;
            $previousHash = $link->currentHash;
        }

        self::assertCount(5, $links);

        // Verify chain integrity
        $verifyHash = HashChain::seedHash();
        foreach ($links as $i => $link) {
            self::assertSame($verifyHash, $link->previousHash);
            $envelope = $this->createTestEnvelope("event-{$i}");
            $expectedHash = hash('sha256', $verifyHash . '|' . $envelope->canonical());
            self::assertSame($expectedHash, $link->currentHash);
            $verifyHash = $link->currentHash;
        }
    }

    #[Test]
    public function verifyLinkHashSucceedsWithValidData(): void
    {
        $previousHash = 'some-previous-hash';
        $canonical = 'event-id|http.request|1|12345||payload-hash';
        $expectedHash = hash('sha256', $previousHash . '|' . $canonical);

        $result = HashChain::verifyLinkHash($previousHash, $canonical, $expectedHash);

        self::assertTrue($result);
    }

    #[Test]
    public function verifyLinkHashFailsWithTamperedPreviousHash(): void
    {
        $previousHash = 'correct-previous-hash';
        $canonical = 'event-id|http.request|1|12345||payload-hash';
        $expectedHash = hash('sha256', $previousHash . '|' . $canonical);

        $result = HashChain::verifyLinkHash('tampered-previous-hash', $canonical, $expectedHash);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyLinkHashFailsWithTamperedCanonical(): void
    {
        $previousHash = 'some-previous-hash';
        $canonical = 'event-id|http.request|1|12345||payload-hash';
        $expectedHash = hash('sha256', $previousHash . '|' . $canonical);

        $result = HashChain::verifyLinkHash($previousHash, 'tampered|canonical', $expectedHash);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyLinkHashFailsWithWrongExpectedHash(): void
    {
        $previousHash = 'some-previous-hash';
        $canonical = 'event-id|http.request|1|12345||payload-hash';

        $result = HashChain::verifyLinkHash($previousHash, $canonical, 'wrong-hash-value');

        self::assertFalse($result);
    }

    #[Test]
    public function verifyLinkMacSucceedsWithValidMac(): void
    {
        $currentHash = 'some-current-hash-value';
        $expectedMac = Hmac::computeHex($currentHash, $this->macKey);

        $result = HashChain::verifyLinkMac($currentHash, $expectedMac, $this->macKey, $this->hmac);

        self::assertTrue($result);
    }

    #[Test]
    public function verifyLinkMacFailsWithWrongMac(): void
    {
        $currentHash = 'some-current-hash-value';

        $result = HashChain::verifyLinkMac($currentHash, str_repeat('f', 64), $this->macKey, $this->hmac);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyLinkMacFailsWithWrongKey(): void
    {
        $currentHash = 'some-current-hash-value';
        $expectedMac = Hmac::computeHex($currentHash, $this->macKey);
        $differentKey = random_bytes(32);

        $result = HashChain::verifyLinkMac($currentHash, $expectedMac, $differentKey, $this->hmac);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyLinkMacFailsWithTamperedHash(): void
    {
        $currentHash = 'original-hash';
        $expectedMac = Hmac::computeHex($currentHash, $this->macKey);

        $result = HashChain::verifyLinkMac('tampered-hash', $expectedMac, $this->macKey, $this->hmac);

        self::assertFalse($result);
    }

    #[Test]
    public function hasMacKeyReturnsTrueWhenKeyProvided(): void
    {
        $chain = new HashChain($this->hmac, $this->macKey);

        self::assertTrue($chain->hasMacKey());
    }

    #[Test]
    public function hasMacKeyReturnsFalseWhenNoKey(): void
    {
        $chain = new HashChain();

        self::assertFalse($chain->hasMacKey());
    }

    #[Test]
    public function hasMacKeyReturnsFalseWithNullKey(): void
    {
        $chain = new HashChain(null, null);

        self::assertFalse($chain->hasMacKey());
    }

    private function createTestEnvelope(string $eventId): EventEnvelope
    {
        return new EventEnvelope(
            eventId: $eventId,
            eventType: EventType::HttpRequest,
            schemaVersion: EventVersion::V1,
            timestampUs: 1234567890123456,
            requestId: 'req-test',
            traceId: 'trace-test',
            spanId: 'span-test',
            jobId: null,
            appEnv: 'testing',
            hostname: 'test-host',
            payload: ['test' => 'data'],
            payloadHash: 'test-payload-hash',
        );
    }
}
