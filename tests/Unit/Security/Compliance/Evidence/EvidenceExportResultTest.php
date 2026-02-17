<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Evidence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Evidence\EvidenceExportResult;

#[CoversClass(EvidenceExportResult::class)]
final class EvidenceExportResultTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $exportedAt = new DateTimeImmutable('2025-01-15T10:30:00+00:00');

        $result = new EvidenceExportResult(
            archiveId: 'archive-001',
            recordCount: 42,
            hashManifest: 'abc123def456',
            exportedAt: $exportedAt,
            encrypted: true,
        );

        self::assertSame('archive-001', $result->archiveId);
        self::assertSame(42, $result->recordCount);
        self::assertSame('abc123def456', $result->hashManifest);
        self::assertSame($exportedAt, $result->exportedAt);
        self::assertTrue($result->encrypted);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $exportedAt = new DateTimeImmutable('2025-03-20T14:00:00.000000+00:00');

        $result = new EvidenceExportResult(
            archiveId: 'archive-002',
            recordCount: 100,
            hashManifest: 'hash-manifest-value',
            exportedAt: $exportedAt,
            encrypted: false,
        );

        $array = $result->toArray();

        self::assertSame('archive-002', $array['archive_id']);
        self::assertSame(100, $array['record_count']);
        self::assertSame('hash-manifest-value', $array['hash_manifest']);
        self::assertSame('2025-03-20T14:00:00.000000+00:00', $array['exported_at']);
        self::assertFalse($array['encrypted']);
    }

    #[Test]
    public function toArrayContainsAllKeys(): void
    {
        $result = new EvidenceExportResult(
            archiveId: 'a',
            recordCount: 0,
            hashManifest: 'h',
            exportedAt: new DateTimeImmutable(),
            encrypted: false,
        );

        $array = $result->toArray();

        self::assertArrayHasKey('archive_id', $array);
        self::assertArrayHasKey('record_count', $array);
        self::assertArrayHasKey('hash_manifest', $array);
        self::assertArrayHasKey('exported_at', $array);
        self::assertArrayHasKey('encrypted', $array);
        self::assertCount(5, $array);
    }
}
