<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Collaboration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Collaboration\CrdtDocument;

#[CoversClass(CrdtDocument::class)]
final class CrdtDocumentTest extends TestCase
{
    private const string CONTENT_ID = 'content-001';

    #[Test]
    public function initial_creates_empty_document(): void
    {
        $doc = CrdtDocument::initial(self::CONTENT_ID);

        self::assertSame(self::CONTENT_ID, $doc->contentId);
        self::assertSame('', $doc->stateVector);
        self::assertSame(1, $doc->version);
    }

    #[Test]
    public function apply_update_returns_new_document_with_incremented_version(): void
    {
        $doc = CrdtDocument::initial(self::CONTENT_ID);
        $updated = $doc->applyUpdate('base64-encoded-state');

        self::assertSame(self::CONTENT_ID, $updated->contentId);
        self::assertSame('base64-encoded-state', $updated->stateVector);
        self::assertSame(2, $updated->version);
    }

    #[Test]
    public function apply_update_does_not_mutate_original(): void
    {
        $doc = CrdtDocument::initial(self::CONTENT_ID);
        $doc->applyUpdate('new-state');

        self::assertSame('', $doc->stateVector);
        self::assertSame(1, $doc->version);
    }

    #[Test]
    public function multiple_updates_increment_version_sequentially(): void
    {
        $doc = CrdtDocument::initial(self::CONTENT_ID);
        $v2 = $doc->applyUpdate('state-v2');
        $v3 = $v2->applyUpdate('state-v3');
        $v4 = $v3->applyUpdate('state-v4');

        self::assertSame(4, $v4->version);
        self::assertSame('state-v4', $v4->stateVector);
    }

    #[Test]
    public function constructor_sets_all_properties(): void
    {
        $now = new DateTimeImmutable();
        $doc = new CrdtDocument(
            contentId: 'c-123',
            stateVector: 'abc123==',
            version: 5,
            updatedAt: $now,
        );

        self::assertSame('c-123', $doc->contentId);
        self::assertSame('abc123==', $doc->stateVector);
        self::assertSame(5, $doc->version);
        self::assertSame($now, $doc->updatedAt);
    }
}
