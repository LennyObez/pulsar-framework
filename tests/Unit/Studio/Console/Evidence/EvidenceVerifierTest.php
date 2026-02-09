<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Evidence;

use function count;
use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceArchive;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceVerifier;
use Pulsar\Extension\Studio\Console\Evidence\HashChain;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\HmacService;

use function random_bytes;

#[CoversClass(EvidenceVerifier::class)]
final class EvidenceVerifierTest extends TestCase
{
    private EvidenceVerifier $verifier;
    private string $macKey;
    private string $archiveMacKey;

    protected function setUp(): void
    {
        $this->verifier = new EvidenceVerifier(new HmacService());
        $this->macKey = random_bytes(32);
        $this->archiveMacKey = random_bytes(32);
    }

    #[Test]
    public function verifyWithEmptyChainReturnsEmptyModeResult(): void
    {
        $result = $this->verifier->verify([], null, 0);

        self::assertSame('empty', $result['mode']);
        self::assertSame('none', $result['anchor_type']);
        self::assertNull($result['earliest_event_id']);
        self::assertNull($result['latest_event_id']);
        self::assertSame(0, $result['links_verified']);
        self::assertSame(0, $result['links_pruned']);
        self::assertTrue($result['chain_intact']);
        self::assertNull($result['mac_verified']);
        self::assertSame([], $result['failures']);
    }

    #[Test]
    public function verifyWithEmptyChainReportsLinksPruned(): void
    {
        $result = $this->verifier->verify([], null, 42);

        self::assertSame('empty', $result['mode']);
        self::assertSame(0, $result['links_verified']);
        self::assertSame(42, $result['links_pruned']);
    }

    #[Test]
    public function verifyWithValidChainFromSeed(): void
    {
        $chainLinks = $this->buildValidChainFromSeed(3);

        $result = $this->verifier->verify($chainLinks);

        self::assertSame('full', $result['mode']);
        self::assertSame('seed', $result['anchor_type']);
        self::assertSame('event-0', $result['earliest_event_id']);
        self::assertSame('event-2', $result['latest_event_id']);
        self::assertSame(3, $result['links_verified']);
        self::assertSame(0, $result['links_pruned']);
        self::assertTrue($result['chain_intact']);
        self::assertNull($result['mac_verified']);
        self::assertSame([], $result['failures']);
    }

    #[Test]
    public function verifyWithValidChainInWindowMode(): void
    {
        // Window mode: chain starts from an arbitrary hash (not seed)
        $windowBoundaryHash = hash('sha256', 'arbitrary-boundary-hash');
        $chainLinks = $this->buildValidChain(3, $windowBoundaryHash);

        $result = $this->verifier->verify($chainLinks, null, 100);

        self::assertSame('window', $result['mode']);
        self::assertSame('window_boundary', $result['anchor_type']);
        self::assertSame('event-0', $result['earliest_event_id']);
        self::assertSame('event-2', $result['latest_event_id']);
        self::assertSame(3, $result['links_verified']);
        self::assertSame(100, $result['links_pruned']);
        self::assertTrue($result['chain_intact']);
        self::assertSame([], $result['failures']);
    }

    #[Test]
    public function verifyWithMacVerificationSuccess(): void
    {
        $chainLinks = $this->buildValidChainWithMac(3, HashChain::seedHash(), $this->macKey);

        $result = $this->verifier->verify($chainLinks, $this->macKey);

        self::assertSame('full', $result['mode']);
        self::assertTrue($result['chain_intact']);
        self::assertTrue($result['mac_verified']);
        self::assertSame([], $result['failures']);
    }

    #[Test]
    public function verifyWithHashMismatchDetectsTampering(): void
    {
        $chainLinks = $this->buildValidChainFromSeed(3);
        // Tamper with the second link's hash
        $chainLinks[1]['current_hash'] = 'tampered-hash-value';

        $result = $this->verifier->verify($chainLinks);

        self::assertFalse($result['chain_intact']);
        self::assertCount(2, $result['failures']); // Link 1 fails, and link 2 fails because previous hash chain is broken

        $failure = $result['failures'][0];
        self::assertSame(1, $failure['index']);
        self::assertSame('event-1', $failure['event_id']);
        self::assertSame('hash mismatch', $failure['reason']);
    }

    #[Test]
    public function verifyWithMacMismatchDetectsTampering(): void
    {
        $chainLinks = $this->buildValidChainWithMac(3, HashChain::seedHash(), $this->macKey);
        // Tamper with the second link's MAC
        $chainLinks[1]['link_mac'] = str_repeat('f', 64);

        $result = $this->verifier->verify($chainLinks, $this->macKey);

        self::assertFalse($result['mac_verified']);
        self::assertCount(1, $result['failures']);

        $failure = $result['failures'][0];
        self::assertSame(1, $failure['index']);
        self::assertSame('event-1', $failure['event_id']);
        self::assertSame('mac mismatch', $failure['reason']);
    }

    #[Test]
    public function verifyDetectsBothHashAndMacMismatch(): void
    {
        $chainLinks = $this->buildValidChainWithMac(3, HashChain::seedHash(), $this->macKey);
        // Tamper with both hash and MAC of the second link
        $chainLinks[1]['current_hash'] = 'tampered-hash';
        $chainLinks[1]['link_mac'] = str_repeat('a', 64);

        $result = $this->verifier->verify($chainLinks, $this->macKey);

        self::assertFalse($result['chain_intact']);
        self::assertFalse($result['mac_verified']);
        // Should have at least 2 failures: hash mismatch for link 1, mac mismatch for link 1
        self::assertGreaterThanOrEqual(2, count($result['failures']));
    }

    #[Test]
    public function verifyWithMissingMacInLinkWhenKeyProvided(): void
    {
        $chainLinks = $this->buildValidChainFromSeed(3);
        // Chain links without MACs, but MAC key is provided

        $result = $this->verifier->verify($chainLinks, $this->macKey);

        // MAC verification is not performed if link_mac is not present
        self::assertTrue($result['chain_intact']);
        self::assertTrue($result['mac_verified']); // Still true because no MAC failures occurred
    }

    #[Test]
    public function verifyArchiveMacSucceedsWithValidMac(): void
    {
        $manifest = [
            'version' => '1.0',
            'exported_at' => '2024-01-01T00:00:00Z',
            'event_count' => 10,
        ];

        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $archiveDigest = hash('sha256', $manifestJson);
        $mac = Hmac::computeHex($archiveDigest, $this->archiveMacKey);

        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: $manifest,
            mac: $mac,
        );

        $result = $this->verifier->verifyArchiveMac($archive, $this->archiveMacKey);

        self::assertTrue($result);
    }

    #[Test]
    public function verifyArchiveMacFailsWithInvalidMac(): void
    {
        $manifest = ['version' => '1.0'];

        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: $manifest,
            mac: str_repeat('f', 64),
        );

        $result = $this->verifier->verifyArchiveMac($archive, $this->archiveMacKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyArchiveMacFailsWithNullMac(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => '1.0'],
            mac: null,
        );

        $result = $this->verifier->verifyArchiveMac($archive, $this->archiveMacKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyArchiveMacFailsWithTamperedManifest(): void
    {
        $originalManifest = ['version' => '1.0', 'event_count' => 10];
        $manifestJson = json_encode($originalManifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $archiveDigest = hash('sha256', $manifestJson);
        $mac = Hmac::computeHex($archiveDigest, $this->archiveMacKey);

        // Create archive with tampered manifest
        $tamperedManifest = ['version' => '1.0', 'event_count' => 999];

        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: $tamperedManifest,
            mac: $mac,
        );

        $result = $this->verifier->verifyArchiveMac($archive, $this->archiveMacKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyArchiveMacFailsWithWrongKey(): void
    {
        $manifest = ['version' => '1.0'];
        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $archiveDigest = hash('sha256', $manifestJson);
        $mac = Hmac::computeHex($archiveDigest, $this->archiveMacKey);

        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: $manifest,
            mac: $mac,
        );

        $wrongKey = random_bytes(32);
        $result = $this->verifier->verifyArchiveMac($archive, $wrongKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifySingleLinkFromSeed(): void
    {
        $chainLinks = $this->buildValidChainFromSeed(1);

        $result = $this->verifier->verify($chainLinks);

        self::assertSame('full', $result['mode']);
        self::assertSame('seed', $result['anchor_type']);
        self::assertSame('event-0', $result['earliest_event_id']);
        self::assertSame('event-0', $result['latest_event_id']);
        self::assertSame(1, $result['links_verified']);
        self::assertTrue($result['chain_intact']);
    }

    #[Test]
    public function verifyHandlesMissingEventIdGracefully(): void
    {
        $chainLinks = $this->buildValidChainFromSeed(1);
        unset($chainLinks[0]['event_id']);

        $result = $this->verifier->verify($chainLinks);

        self::assertSame('', $result['earliest_event_id']);
        self::assertSame('', $result['latest_event_id']);
    }

    #[Test]
    public function verifyHandlesMissingFieldsInLinks(): void
    {
        $chainLinks = [
            [
                'event_id' => 'evt-1',
                'previous_hash' => HashChain::seedHash(),
                // Missing event_type, schema_version, timestamp_us, trace_id, payload_hash
                // Will use empty strings for canonical
            ],
        ];

        // Compute what the hash would be with empty canonical fields
        $canonical = 'evt-1|||||';
        $currentHash = hash('sha256', HashChain::seedHash() . '|' . $canonical);
        $chainLinks[0]['current_hash'] = $currentHash;

        $result = $this->verifier->verify($chainLinks);

        self::assertTrue($result['chain_intact']);
        self::assertSame(1, $result['links_verified']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildValidChainFromSeed(int $count): array
    {
        return $this->buildValidChain($count, HashChain::seedHash());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildValidChain(int $count, string $startHash): array
    {
        $links = [];
        $previousHash = $startHash;

        for ($i = 0; $i < $count; $i++) {
            $eventId = "event-{$i}";
            $eventType = 'http.request';
            $schemaVersion = '1';
            $timestampUs = (string) (1234567890123456 + $i);
            $traceId = "trace-{$i}";
            $payloadHash = hash('sha256', "payload-{$i}");

            $canonical = "{$eventId}|{$eventType}|{$schemaVersion}|{$timestampUs}|{$traceId}|{$payloadHash}";
            $currentHash = hash('sha256', $previousHash . '|' . $canonical);

            $links[] = [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'schema_version' => $schemaVersion,
                'timestamp_us' => $timestampUs,
                'trace_id' => $traceId,
                'payload_hash' => $payloadHash,
                'previous_hash' => $previousHash,
                'current_hash' => $currentHash,
            ];

            $previousHash = $currentHash;
        }

        return $links;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildValidChainWithMac(int $count, string $startHash, string $macKey): array
    {
        $links = $this->buildValidChain($count, $startHash);

        foreach ($links as &$link) {
            /** @var string $currentHash */
            $currentHash = $link['current_hash'];
            $link['link_mac'] = Hmac::computeHex($currentHash, $macKey);
        }

        return $links;
    }
}
