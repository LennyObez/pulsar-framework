<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceArchive;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceVerifier;
use Pulsar\Extension\Studio\Console\Evidence\HashChain;
use Pulsar\Security\Crypto\HmacInterface;

use function hash;

#[CoversClass(EvidenceVerifier::class)]
final class EvidenceVerifierTest extends TestCase
{
    #[Test]
    public function emptyChainReturnsEmptyResult(): void
    {
        $verifier = new EvidenceVerifier();
        $result = $verifier->verify([]);

        self::assertSame('empty', $result['mode']);
        self::assertSame('none', $result['anchor_type']);
        self::assertNull($result['earliest_event_id']);
        self::assertNull($result['latest_event_id']);
        self::assertSame(0, $result['links_verified']);
        self::assertTrue($result['chain_intact']);
        self::assertNull($result['mac_verified']);
        self::assertSame([], $result['failures']);
    }

    #[Test]
    public function emptyChainRespectsLinksPruned(): void
    {
        $verifier = new EvidenceVerifier();
        $result = $verifier->verify([], linksPruned: 50);

        self::assertSame(50, $result['links_pruned']);
    }

    #[Test]
    public function validFullChainFromSeedPasses(): void
    {
        $verifier = new EvidenceVerifier();
        $seedHash = HashChain::seedHash();

        $canonical = 'e1|http.response|1|1700000000|trace1|payload_hash1';
        $currentHash = hash('sha256', $seedHash . '|' . $canonical);

        $chain = [
            [
                'event_id' => 'e1',
                'event_type' => 'http.response',
                'schema_version' => '1',
                'timestamp_us' => '1700000000',
                'trace_id' => 'trace1',
                'payload_hash' => 'payload_hash1',
                'previous_hash' => $seedHash,
                'current_hash' => $currentHash,
            ],
        ];

        $result = $verifier->verify($chain);

        self::assertSame('full', $result['mode']);
        self::assertSame('seed', $result['anchor_type']);
        self::assertSame('e1', $result['earliest_event_id']);
        self::assertSame('e1', $result['latest_event_id']);
        self::assertSame(1, $result['links_verified']);
        self::assertTrue($result['chain_intact']);
        self::assertSame([], $result['failures']);
    }

    #[Test]
    public function windowModeWhenNotStartingFromSeed(): void
    {
        $verifier = new EvidenceVerifier();
        $previousHash = 'some-arbitrary-previous-hash';

        $canonical = 'e1|db.query|1|1700000000||payload_hash1';
        $currentHash = hash('sha256', $previousHash . '|' . $canonical);

        $chain = [
            [
                'event_id' => 'e1',
                'event_type' => 'db.query',
                'schema_version' => '1',
                'timestamp_us' => '1700000000',
                'trace_id' => '',
                'payload_hash' => 'payload_hash1',
                'previous_hash' => $previousHash,
                'current_hash' => $currentHash,
            ],
        ];

        $result = $verifier->verify($chain);

        self::assertSame('window', $result['mode']);
        self::assertSame('window_boundary', $result['anchor_type']);
        self::assertTrue($result['chain_intact']);
    }

    #[Test]
    public function tamperedHashIsDetected(): void
    {
        $verifier = new EvidenceVerifier();
        $seedHash = HashChain::seedHash();

        $chain = [
            [
                'event_id' => 'e1',
                'event_type' => 'http.response',
                'schema_version' => '1',
                'timestamp_us' => '1700000000',
                'trace_id' => '',
                'payload_hash' => 'ph1',
                'previous_hash' => $seedHash,
                'current_hash' => 'tampered_hash_value',
            ],
        ];

        $result = $verifier->verify($chain);

        self::assertFalse($result['chain_intact']);
        self::assertCount(1, $result['failures']);
        self::assertSame(0, $result['failures'][0]['index']);
        self::assertSame('e1', $result['failures'][0]['event_id']);
        self::assertSame('hash mismatch', $result['failures'][0]['reason']);
    }

    #[Test]
    public function verifyArchiveMacReturnsFalseWhenNoMac(): void
    {
        $verifier = new EvidenceVerifier();
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: null,
        );

        self::assertFalse($verifier->verifyArchiveMac($archive, 'some-key'));
    }

    #[Test]
    public function verifyArchiveMacReturnsFalseWhenNoHmac(): void
    {
        $verifier = new EvidenceVerifier(hmac: null);
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: 'some-mac-value',
        );

        self::assertFalse($verifier->verifyArchiveMac($archive, 'some-key'));
    }

    #[Test]
    public function verifyArchiveMacDelegatesToHmac(): void
    {
        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('verifyHex')->willReturn(true);

        $verifier = new EvidenceVerifier(hmac: $hmac);
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: 'valid-mac',
        );

        self::assertTrue($verifier->verifyArchiveMac($archive, 'key'));
    }

    #[Test]
    public function macVerificationTracksFailures(): void
    {
        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('verifyHex')->willReturn(false);

        $verifier = new EvidenceVerifier(hmac: $hmac);
        $seedHash = HashChain::seedHash();

        $canonical = 'e1|exception|1|1700000000||ph1';
        $currentHash = hash('sha256', $seedHash . '|' . $canonical);

        $chain = [
            [
                'event_id' => 'e1',
                'event_type' => 'exception',
                'schema_version' => '1',
                'timestamp_us' => '1700000000',
                'trace_id' => '',
                'payload_hash' => 'ph1',
                'previous_hash' => $seedHash,
                'current_hash' => $currentHash,
                'link_mac' => 'bad-mac',
            ],
        ];

        $result = $verifier->verify($chain, chainMacKey: 'key');

        self::assertFalse($result['mac_verified']);
        self::assertCount(1, $result['failures']);
        self::assertSame('mac mismatch', $result['failures'][0]['reason']);
    }
}
