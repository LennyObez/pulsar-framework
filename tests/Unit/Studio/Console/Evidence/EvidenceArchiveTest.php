<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Evidence;

use InvalidArgumentException;

use function json_decode;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Evidence\EvidenceArchive;
use ReflectionClass;

use function time;

#[CoversClass(EvidenceArchive::class)]
final class EvidenceArchiveTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $events = [
            ['event_id' => 'evt-1', 'event_type' => 'http_request'],
            ['event_id' => 'evt-2', 'event_type' => 'http_response'],
        ];
        $chainLinks = [
            ['event_id' => 'evt-1', 'current_hash' => 'hash1'],
        ];
        $manifest = [
            'version' => 1,
            'exported_at' => time(),
            'event_count' => 2,
        ];
        $mac = 'abcdef1234567890';

        $archive = new EvidenceArchive(
            events: $events,
            chainLinks: $chainLinks,
            manifest: $manifest,
            mac: $mac,
        );

        self::assertSame($events, $archive->events);
        self::assertSame($chainLinks, $archive->chainLinks);
        self::assertSame($manifest, $archive->manifest);
        self::assertSame($mac, $archive->mac);
    }

    #[Test]
    public function constructorAllowsNullMac(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
        );

        self::assertNull($archive->mac);
    }

    #[Test]
    public function toJsonSerializesArchiveCorrectly(): void
    {
        $events = [
            ['event_id' => 'evt-1', 'payload' => ['method' => 'GET']],
        ];
        $chainLinks = [
            ['event_id' => 'evt-1', 'hash' => 'abc123'],
        ];
        $manifest = [
            'version' => 1,
            'exported_at' => 1700000000,
            'event_count' => 1,
        ];
        $mac = 'test-mac-value';

        $archive = new EvidenceArchive($events, $chainLinks, $manifest, $mac);

        $json = $archive->toJson();
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('events', $decoded);
        self::assertArrayHasKey('chain', $decoded);
        self::assertArrayHasKey('manifest', $decoded);
        self::assertArrayHasKey('mac', $decoded);

        self::assertSame($events, $decoded['events']);
        self::assertSame($chainLinks, $decoded['chain']);
        self::assertSame($manifest, $decoded['manifest']);
        self::assertSame($mac, $decoded['mac']);
    }

    #[Test]
    public function toJsonPrettyPrintsOutput(): void
    {
        $archive = new EvidenceArchive(
            events: [['id' => 1]],
            chainLinks: [],
            manifest: ['version' => 1],
        );

        $json = $archive->toJson();

        // Pretty-printed JSON contains newlines
        self::assertStringContainsString("\n", $json);
    }

    #[Test]
    public function toJsonPreservesSlashesAndUnicode(): void
    {
        $archive = new EvidenceArchive(
            events: [['url' => 'https://example.com/path', 'name' => 'Cafe']],
            chainLinks: [],
            manifest: ['version' => 1],
        );

        $json = $archive->toJson();

        // Slashes should not be escaped
        self::assertStringContainsString('https://example.com/path', $json);
        // Unicode should not be escaped
        self::assertStringContainsString('Cafe', $json);
    }

    #[Test]
    public function toJsonIncludesNullMac(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: null,
        );

        $json = $archive->toJson();
        /** @var array{events: list<mixed>, chain: list<mixed>, manifest: array<string, mixed>, mac: ?string} $decoded */
        $decoded = json_decode($json, true);

        self::assertArrayHasKey('mac', $decoded);
        self::assertNull($decoded['mac']);
    }

    #[Test]
    public function fromJsonDeserializesArchive(): void
    {
        $events = [
            ['event_id' => 'evt-1', 'event_type' => 'http_request'],
        ];
        $chainLinks = [
            ['event_id' => 'evt-1', 'current_hash' => 'hash1'],
        ];
        $manifest = [
            'version' => 1,
            'exported_at' => 1700000000,
        ];
        $mac = 'test-mac';

        $json = (string) json_encode([
            'events' => $events,
            'chain' => $chainLinks,
            'manifest' => $manifest,
            'mac' => $mac,
        ]);

        $archive = EvidenceArchive::fromJson($json);

        self::assertSame($events, $archive->events);
        self::assertSame($chainLinks, $archive->chainLinks);
        self::assertSame($manifest, $archive->manifest);
        self::assertSame($mac, $archive->mac);
    }

    #[Test]
    public function fromJsonHandlesNullMac(): void
    {
        $json = (string) json_encode([
            'events' => [],
            'chain' => [],
            'manifest' => ['version' => 1],
            'mac' => null,
        ]);

        $archive = EvidenceArchive::fromJson($json);

        self::assertNull($archive->mac);
    }

    #[Test]
    public function fromJsonHandlesMissingMac(): void
    {
        $json = (string) json_encode([
            'events' => [],
            'chain' => [],
            'manifest' => ['version' => 1],
        ]);

        $archive = EvidenceArchive::fromJson($json);

        self::assertNull($archive->mac);
    }

    #[Test]
    public function roundTripPreservesData(): void
    {
        $events = [
            ['event_id' => 'evt-1', 'data' => ['key' => 'value']],
            ['event_id' => 'evt-2', 'data' => ['nested' => ['a' => 1, 'b' => 2]]],
        ];
        $chainLinks = [
            ['event_id' => 'evt-1', 'previous_hash' => 'seed', 'current_hash' => 'hash1'],
            ['event_id' => 'evt-2', 'previous_hash' => 'hash1', 'current_hash' => 'hash2'],
        ];
        $manifest = [
            'version' => 1,
            'exported_at' => 1700000000,
            'event_count' => 2,
            'chain_link_count' => 2,
            'filters' => ['event_type' => 'http_request'],
        ];
        $mac = 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890';

        $original = new EvidenceArchive($events, $chainLinks, $manifest, $mac);
        $json = $original->toJson();
        $restored = EvidenceArchive::fromJson($json);

        self::assertSame($original->events, $restored->events);
        self::assertSame($original->chainLinks, $restored->chainLinks);
        self::assertSame($original->manifest, $restored->manifest);
        self::assertSame($original->mac, $restored->mac);
    }

    #[Test]
    public function fromJsonThrowsOnInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $_ = EvidenceArchive::fromJson('not valid json');
    }

    #[Test]
    public function toJsonHandlesEmptyArchive(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
        );

        $json = $archive->toJson();
        /** @var array{events: list<mixed>, chain: list<mixed>, manifest: array<string, mixed>, mac: ?string} $decoded */
        $decoded = json_decode($json, true);

        self::assertSame([], $decoded['events']);
        self::assertSame([], $decoded['chain']);
    }

    #[Test]
    public function toJsonHandlesComplexNestedData(): void
    {
        $events = [
            [
                'event_id' => 'evt-1',
                'payload' => [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Custom' => ['value1', 'value2'],
                    ],
                    'body' => [
                        'users' => [
                            ['id' => 1, 'name' => 'Alice'],
                            ['id' => 2, 'name' => 'Bob'],
                        ],
                    ],
                ],
            ],
        ];

        $archive = new EvidenceArchive(
            events: $events,
            chainLinks: [],
            manifest: ['version' => 1],
        );

        $json = $archive->toJson();
        $restored = EvidenceArchive::fromJson($json);

        self::assertSame($events, $restored->events);
    }

    #[Test]
    public function archiveIsReadonly(): void
    {
        $archive = new EvidenceArchive(
            events: [['id' => 1]],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: 'test',
        );

        // Verify the class is marked readonly by checking properties exist
        $reflection = new ReflectionClass($archive);
        self::assertTrue($reflection->isReadOnly());
    }
}
