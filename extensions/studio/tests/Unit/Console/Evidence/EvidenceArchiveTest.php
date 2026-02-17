<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Evidence;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceArchive;

#[CoversClass(EvidenceArchive::class)]
final class EvidenceArchiveTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $events = [['event_id' => 'e1', 'type' => 'test']];
        $chainLinks = [['event_id' => 'e1', 'hash' => 'abc']];
        $manifest = ['version' => 1, 'event_count' => 1];

        $archive = new EvidenceArchive(
            events: $events,
            chainLinks: $chainLinks,
            manifest: $manifest,
            mac: 'mac-hex-value',
        );

        self::assertSame($events, $archive->events);
        self::assertSame($chainLinks, $archive->chainLinks);
        self::assertSame($manifest, $archive->manifest);
        self::assertSame('mac-hex-value', $archive->mac);
    }

    #[Test]
    public function macDefaultsToNull(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
        );

        self::assertNull($archive->mac);
    }

    #[Test]
    public function toJsonProducesValidJson(): void
    {
        $archive = new EvidenceArchive(
            events: [['event_id' => 'e1']],
            chainLinks: [['hash' => 'h1']],
            manifest: ['version' => 1],
            mac: 'abc123',
        );

        $json = $archive->toJson();
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([['event_id' => 'e1']], $decoded['events']);
        self::assertSame([['hash' => 'h1']], $decoded['chain']);
        self::assertSame(['version' => 1], $decoded['manifest']);
        self::assertSame('abc123', $decoded['mac']);
    }

    #[Test]
    public function fromJsonDeserializesCorrectly(): void
    {
        $original = new EvidenceArchive(
            events: [['event_id' => 'e1', 'type' => 'http']],
            chainLinks: [['link' => 'l1']],
            manifest: ['version' => 1, 'exported_at' => 1700000000],
            mac: 'mac-value',
        );

        $restored = EvidenceArchive::fromJson($original->toJson());

        self::assertSame($original->events, $restored->events);
        self::assertSame($original->chainLinks, $restored->chainLinks);
        self::assertSame($original->manifest, $restored->manifest);
        self::assertSame($original->mac, $restored->mac);
    }

    #[Test]
    public function fromJsonWithoutMac(): void
    {
        $json = json_encode([
            'events' => [],
            'chain' => [],
            'manifest' => ['version' => 1],
        ], JSON_THROW_ON_ERROR);

        $archive = EvidenceArchive::fromJson($json);

        self::assertNull($archive->mac);
    }

    #[Test]
    public function fromJsonThrowsOnInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        (void) EvidenceArchive::fromJson('not valid json{{{');
    }

    #[Test]
    public function fromJsonThrowsOnMissingKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive missing required keys');

        (void) EvidenceArchive::fromJson('{"events": []}');
    }

    #[Test]
    public function roundTripPreservesEmptyArchive(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
        );

        $restored = EvidenceArchive::fromJson($archive->toJson());

        self::assertSame([], $restored->events);
        self::assertSame([], $restored->chainLinks);
    }
}
