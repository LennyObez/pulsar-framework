<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Evidence;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceArchive;

#[CoversClass(EvidenceArchive::class)]
final class EvidenceArchiveTest extends TestCase
{
    #[Test]
    public function constructionWithAllFields(): void
    {
        $archive = new EvidenceArchive(
            events: [['type' => 'http.request', 'data' => []]],
            chainLinks: [['hash' => 'abc', 'prev' => null]],
            manifest: ['version' => 1, 'exported_at' => '2026-01-01'],
            mac: 'mac-signature',
        );

        self::assertCount(1, $archive->events);
        self::assertSame('http.request', $archive->events[0]['type']);
        self::assertCount(1, $archive->chainLinks);
        self::assertSame(1, $archive->manifest['version']);
        self::assertSame('mac-signature', $archive->mac);
    }

    #[Test]
    public function constructionWithoutMac(): void
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
            events: [['type' => 'test']],
            chainLinks: [['hash' => 'h1']],
            manifest: ['count' => 1],
            mac: 'sig',
        );

        $json = $archive->toJson();

        self::assertJson($json);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('events', $decoded);
        self::assertArrayHasKey('chain', $decoded);
        self::assertArrayHasKey('manifest', $decoded);
        self::assertSame('sig', $decoded['mac']);
        self::assertIsArray($decoded['events']);
        self::assertIsArray($decoded['events'][0]);
        self::assertSame('test', $decoded['events'][0]['type']);
    }

    #[Test]
    public function toJsonWithNullMac(): void
    {
        $archive = new EvidenceArchive([], [], ['v' => 1]);
        $json = $archive->toJson();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertNull($decoded['mac']);
    }

    #[Test]
    public function fromJsonRoundTrip(): void
    {
        $original = new EvidenceArchive(
            events: [['type' => 'db.query', 'sql' => 'SELECT 1']],
            chainLinks: [['hash' => 'abc123', 'prev' => null]],
            manifest: ['version' => 1, 'event_count' => 1],
            mac: 'hmac-value',
        );

        $json = $original->toJson();
        $restored = EvidenceArchive::fromJson($json);

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
            'manifest' => ['v' => 1],
        ], JSON_THROW_ON_ERROR);

        $archive = EvidenceArchive::fromJson($json);

        self::assertNull($archive->mac);
    }

    #[Test]
    public function fromJsonRejectsInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid JSON');

        (void) EvidenceArchive::fromJson('not json {{{');
    }

    #[Test]
    public function fromJsonRejectsMissingEvents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('missing required keys');

        (void) EvidenceArchive::fromJson(json_encode([
            'chain' => [],
            'manifest' => [],
        ], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function fromJsonRejectsMissingChain(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (void) EvidenceArchive::fromJson(json_encode([
            'events' => [],
            'manifest' => [],
        ], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function fromJsonRejectsMissingManifest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (void) EvidenceArchive::fromJson(json_encode([
            'events' => [],
            'chain' => [],
        ], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function fromJsonIgnoresNonStringMac(): void
    {
        $json = json_encode([
            'events' => [],
            'chain' => [],
            'manifest' => [],
            'mac' => 12345,
        ], JSON_THROW_ON_ERROR);

        $archive = EvidenceArchive::fromJson($json);

        self::assertNull($archive->mac);
    }

    #[Test]
    public function toJsonUsesUnescapedSlashes(): void
    {
        $archive = new EvidenceArchive(
            events: [['url' => 'https://example.com/api/v1']],
            chainLinks: [],
            manifest: [],
        );

        $json = $archive->toJson();

        self::assertStringContainsString('https://example.com/api/v1', $json);
        self::assertStringNotContainsString('\\/', $json);
    }
}
