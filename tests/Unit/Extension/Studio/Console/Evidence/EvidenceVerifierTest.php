<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceArchive;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceVerifier;
use Pulsar\Extension\Studio\Console\Evidence\HashChain;
use Pulsar\Security\Crypto\HmacInterface;

use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

#[CoversClass(EvidenceVerifier::class)]
final class EvidenceVerifierTest extends TestCase
{
    /**
     * @return array{event_id: string, event_type: string, schema_version: string, timestamp_us: string, trace_id: string, payload_hash: string, previous_hash: string, current_hash: string, link_mac: ?string}
     */
    private function buildChainLink(
        string $eventId,
        string $eventType,
        string $schemaVersion,
        string $timestampUs,
        string $traceId,
        string $payloadHash,
        string $previousHash,
        ?string $linkMac = null,
    ): array {
        $canonical = $eventId . '|' . $eventType . '|' . $schemaVersion . '|' . $timestampUs . '|' . $traceId . '|' . $payloadHash;
        $currentHash = hash('sha256', $previousHash . '|' . $canonical);

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'schema_version' => $schemaVersion,
            'timestamp_us' => $timestampUs,
            'trace_id' => $traceId,
            'payload_hash' => $payloadHash,
            'previous_hash' => $previousHash,
            'current_hash' => $currentHash,
            'link_mac' => $linkMac,
        ];
    }

    #[Test]
    public function verifyEmptyChainReturnsEmptyResult(): void
    {
        $verifier = new EvidenceVerifier();
        $result = $verifier->verify([], linksPruned: 5);

        self::assertSame('empty', $result['mode']);
        self::assertSame('none', $result['anchor_type']);
        self::assertNull($result['earliest_event_id']);
        self::assertNull($result['latest_event_id']);
        self::assertSame(0, $result['links_verified']);
        self::assertSame(5, $result['links_pruned']);
        self::assertTrue($result['chain_intact']);
        self::assertNull($result['mac_verified']);
        self::assertSame([], $result['failures']);
    }

    #[Test]
    public function verifyFullChainFromSeed(): void
    {
        $seedHash = HashChain::seedHash();

        $link1 = $this->buildChainLink('ev1', 'http.request', '1', '1000', 'trace-1', 'hash-1', $seedHash);
        $link2 = $this->buildChainLink('ev2', 'http.response', '1', '2000', 'trace-1', 'hash-2', $link1['current_hash']);
        $link3 = $this->buildChainLink('ev3', 'db.query', '1', '3000', 'trace-1', 'hash-3', $link2['current_hash']);

        $verifier = new EvidenceVerifier();
        /** @var list<array<string, mixed>> $chain */
        $chain = [$link1, $link2, $link3];
        $result = $verifier->verify($chain);

        self::assertSame('full', $result['mode']);
        self::assertSame('seed', $result['anchor_type']);
        self::assertSame('ev1', $result['earliest_event_id']);
        self::assertSame('ev3', $result['latest_event_id']);
        self::assertSame(3, $result['links_verified']);
        self::assertSame(0, $result['links_pruned']);
        self::assertTrue($result['chain_intact']);
        self::assertNull($result['mac_verified']);
        self::assertSame([], $result['failures']);
    }

    #[Test]
    public function verifyWindowChain(): void
    {
        $windowBoundaryHash = hash('sha256', 'arbitrary-boundary');

        $link1 = $this->buildChainLink('ev1', 'http.request', '1', '1000', 'trace-1', 'hash-1', $windowBoundaryHash);
        $link2 = $this->buildChainLink('ev2', 'http.response', '1', '2000', 'trace-1', 'hash-2', $link1['current_hash']);

        $verifier = new EvidenceVerifier();
        /** @var list<array<string, mixed>> $chain */
        $chain = [$link1, $link2];
        $result = $verifier->verify($chain, linksPruned: 10);

        self::assertSame('window', $result['mode']);
        self::assertSame('window_boundary', $result['anchor_type']);
        self::assertSame(2, $result['links_verified']);
        self::assertSame(10, $result['links_pruned']);
        self::assertTrue($result['chain_intact']);
    }

    #[Test]
    public function verifyDetectsTamperedHash(): void
    {
        $seedHash = HashChain::seedHash();

        $link1 = $this->buildChainLink('ev1', 'http.request', '1', '1000', 'trace-1', 'hash-1', $seedHash);
        $link2 = $this->buildChainLink('ev2', 'http.response', '1', '2000', 'trace-1', 'hash-2', $link1['current_hash']);

        // Tamper with link2's hash
        $link2['current_hash'] = hash('sha256', 'tampered');

        $verifier = new EvidenceVerifier();
        /** @var list<array<string, mixed>> $chain */
        $chain = [$link1, $link2];
        $result = $verifier->verify($chain);

        self::assertFalse($result['chain_intact']);
        self::assertCount(1, $result['failures']);
        self::assertSame(1, $result['failures'][0]['index']);
        self::assertSame('ev2', $result['failures'][0]['event_id']);
        self::assertSame('hash mismatch', $result['failures'][0]['reason']);
    }

    #[Test]
    public function verifySingleLink(): void
    {
        $seedHash = HashChain::seedHash();
        $link = $this->buildChainLink('ev1', 'http.request', '1', '1000', '', 'hash-1', $seedHash);

        $verifier = new EvidenceVerifier();
        /** @var list<array<string, mixed>> $chain */
        $chain = [$link];
        $result = $verifier->verify($chain);

        self::assertSame('full', $result['mode']);
        self::assertSame(1, $result['links_verified']);
        self::assertTrue($result['chain_intact']);
        self::assertSame('ev1', $result['earliest_event_id']);
        self::assertSame('ev1', $result['latest_event_id']);
    }

    #[Test]
    public function verifyWithMacKeyInitializesMacVerified(): void
    {
        $seedHash = HashChain::seedHash();
        $link = $this->buildChainLink('ev1', 'http.request', '1', '1000', '', 'hash-1', $seedHash);

        $verifier = new EvidenceVerifier();
        /** @var list<array<string, mixed>> $chain */
        $chain = [$link];
        $result = $verifier->verify($chain, chainMacKey: 'test-key');

        // MAC verification is true by default when key is provided (no failures override it)
        self::assertTrue($result['mac_verified']);
    }

    #[Test]
    public function verifyWithMacKeyDetectsBadMac(): void
    {
        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('verifyHex')->willReturn(false);

        $seedHash = HashChain::seedHash();
        $link = $this->buildChainLink('ev1', 'http.request', '1', '1000', '', 'hash-1', $seedHash, linkMac: 'bad-mac');

        $verifier = new EvidenceVerifier($hmac);
        /** @var list<array<string, mixed>> $chain */
        $chain = [$link];
        $result = $verifier->verify($chain, chainMacKey: 'test-key');

        self::assertFalse($result['mac_verified']);
        self::assertFalse($result['chain_intact']);
        self::assertCount(1, $result['failures']);
        self::assertSame('mac mismatch', $result['failures'][0]['reason']);
    }

    #[Test]
    public function verifyWithMacKeyAcceptsValidMac(): void
    {
        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('verifyHex')->willReturn(true);

        $seedHash = HashChain::seedHash();
        $link = $this->buildChainLink('ev1', 'http.request', '1', '1000', '', 'hash-1', $seedHash, linkMac: 'valid-mac');

        $verifier = new EvidenceVerifier($hmac);
        /** @var list<array<string, mixed>> $chain */
        $chain = [$link];
        $result = $verifier->verify($chain, chainMacKey: 'test-key');

        self::assertTrue($result['mac_verified']);
        self::assertTrue($result['chain_intact']);
    }

    #[Test]
    public function verifyArchiveMacReturnsFalseWhenNoMac(): void
    {
        $archive = new EvidenceArchive(events: [], chainLinks: [], manifest: [], mac: null);

        $verifier = new EvidenceVerifier();
        self::assertFalse($verifier->verifyArchiveMac($archive, 'key'));
    }

    #[Test]
    public function verifyArchiveMacReturnsFalseWhenNoHmac(): void
    {
        $archive = new EvidenceArchive(events: [], chainLinks: [], manifest: ['test' => 1], mac: 'some-mac');

        $verifier = new EvidenceVerifier(null);
        self::assertFalse($verifier->verifyArchiveMac($archive, 'key'));
    }

    #[Test]
    public function verifyArchiveMacDelegatesToHmac(): void
    {
        $manifest = ['event_count' => 3, 'created_at' => '2025-01-01'];
        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $archiveDigest = hash('sha256', $manifestJson);

        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('verifyHex')->willReturnCallback(
            fn(string $msg, string $hex, string $key): bool => $msg === $archiveDigest && $hex === 'valid-mac' && $key === 'archive-key',
        );

        $archive = new EvidenceArchive(events: [], chainLinks: [], manifest: $manifest, mac: 'valid-mac');

        $verifier = new EvidenceVerifier($hmac);
        self::assertTrue($verifier->verifyArchiveMac($archive, 'archive-key'));
    }

    #[Test]
    public function verifyArchiveMacReturnsFalseOnBadMac(): void
    {
        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('verifyHex')->willReturn(false);

        $archive = new EvidenceArchive(events: [], chainLinks: [], manifest: ['data' => 1], mac: 'bad-mac');

        $verifier = new EvidenceVerifier($hmac);
        self::assertFalse($verifier->verifyArchiveMac($archive, 'archive-key'));
    }

    #[Test]
    public function verifyHandlesLinksWithMissingFields(): void
    {
        $seedHash = HashChain::seedHash();
        // Build canonical from empty defaults
        $canonical = '||||' . '|';
        $currentHash = hash('sha256', $seedHash . '|' . $canonical);

        $link = [
            'previous_hash' => $seedHash,
            'current_hash' => $currentHash,
        ];

        $verifier = new EvidenceVerifier();
        /** @var list<array<string, mixed>> $chain */
        $chain = [$link];
        $result = $verifier->verify($chain);

        self::assertTrue($result['chain_intact']);
        self::assertSame(1, $result['links_verified']);
    }
}
