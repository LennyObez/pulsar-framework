<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ImportExport;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ImportExport\ExportResult;

#[CoversClass(ExportResult::class)]
final class ExportResultTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredFields(): void
    {
        $result = new ExportResult(
            providerName: 'cms',
            data: ['content' => [['id' => '1', 'title' => 'Hello']]],
            format: 'json',
            evidenceHash: 'abc123',
            entityTypes: ['content'],
        );

        self::assertSame('cms', $result->providerName);
        self::assertSame('json', $result->format);
        self::assertSame('abc123', $result->evidenceHash);
        self::assertSame(['content'], $result->entityTypes);
        self::assertSame([], $result->warnings);
        self::assertInstanceOf(DateTimeImmutable::class, $result->createdAt);
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $createdAt = new DateTimeImmutable('2026-03-21T12:00:00+00:00');

        $result = new ExportResult(
            providerName: 'forum',
            data: ['tags' => [['slug' => 'php']]],
            format: 'json',
            evidenceHash: 'deadbeef',
            entityTypes: ['tags'],
            warnings: ['Some tags have no usage'],
            createdAt: $createdAt,
        );

        $array = $result->toArray();

        self::assertSame('forum', $array['provider']);
        self::assertSame('deadbeef', $array['evidence_hash']);
        self::assertSame(['tags'], $array['entity_types']);
        self::assertSame(['Some tags have no usage'], $array['warnings']);
        self::assertSame('2026-03-21T12:00:00+00:00', $array['created_at']);
    }
}
