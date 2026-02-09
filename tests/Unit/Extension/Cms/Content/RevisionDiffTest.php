<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentRevision;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\FieldDiff;
use Pulsar\Extension\Cms\Content\RevisionDiff;
use Pulsar\Extension\Cms\Content\RevisionService;
use Pulsar\Extension\Cms\Exception\CmsException;

#[CoversClass(RevisionService::class)]
#[CoversClass(RevisionDiff::class)]
#[CoversClass(FieldDiff::class)]
final class RevisionDiffTest extends TestCase
{
    private ContentRevisionRepositoryInterface&Stub $revisionRepository;
    private ContentTranslationRepositoryInterface&Stub $translationRepository;
    private RevisionService $service;

    protected function setUp(): void
    {
        $this->revisionRepository = $this->createStub(ContentRevisionRepositoryInterface::class);
        $this->translationRepository = $this->createStub(ContentTranslationRepositoryInterface::class);

        $this->service = new RevisionService(
            $this->revisionRepository,
            $this->translationRepository,
        );
    }

    #[Test]
    public function computeDiffDetectsChangedFields(): void
    {
        $from = $this->makeRevision(
            id: 'rev-aaa',
            title: 'Old Title',
            slug: 'old-slug',
            body: 'Old body content',
            excerpt: 'Old excerpt',
            metaTitle: 'Old Meta',
            metaDescription: 'Old description',
        );

        $to = $this->makeRevision(
            id: 'rev-bbb',
            title: 'New Title',
            slug: 'old-slug',
            body: 'New body content',
            excerpt: 'Old excerpt',
            metaTitle: null,
            metaDescription: 'New description',
        );

        $this->revisionRepository->method('findById')->willReturnCallback(
            static fn(string $id) => match ($id) {
                'rev-aaa' => $from,
                'rev-bbb' => $to,
                default => null,
            },
        );

        $diff = $this->service->computeDiff('rev-aaa', 'rev-bbb');

        self::assertSame('rev-aaa', $diff->fromRevisionId);
        self::assertSame('rev-bbb', $diff->toRevisionId);
        self::assertTrue($diff->hasChanges());
        self::assertCount(4, $diff->changes);

        $fields = array_map(static fn(FieldDiff $c) => $c->field, $diff->changes);
        self::assertContains('title', $fields);
        self::assertContains('body', $fields);
        self::assertContains('metaTitle', $fields);
        self::assertContains('metaDescription', $fields);
        self::assertNotContains('slug', $fields);
        self::assertNotContains('excerpt', $fields);

        // Verify specific change values
        $titleChange = $this->findChange($diff, 'title');
        self::assertSame('Old Title', $titleChange->from);
        self::assertSame('New Title', $titleChange->to);

        $metaTitleChange = $this->findChange($diff, 'metaTitle');
        self::assertSame('Old Meta', $metaTitleChange->from);
        self::assertNull($metaTitleChange->to);
    }

    #[Test]
    public function computeDiffReturnsEmptyChangesForIdenticalRevisions(): void
    {
        $from = $this->makeRevision(id: 'rev-aaa');
        $to = $this->makeRevision(id: 'rev-bbb');

        $this->revisionRepository->method('findById')->willReturnCallback(
            static fn(string $id) => match ($id) {
                'rev-aaa' => $from,
                'rev-bbb' => $to,
                default => null,
            },
        );

        $diff = $this->service->computeDiff('rev-aaa', 'rev-bbb');

        self::assertFalse($diff->hasChanges());
        self::assertSame([], $diff->changes);
    }

    #[Test]
    public function computeDiffThrowsWhenFromRevisionNotFound(): void
    {
        $this->revisionRepository->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Revision not found: rev-missing');

        $this->service->computeDiff('rev-missing', 'rev-bbb');
    }

    #[Test]
    public function computeDiffThrowsWhenToRevisionNotFound(): void
    {
        $from = $this->makeRevision(id: 'rev-aaa');

        $this->revisionRepository->method('findById')->willReturnCallback(
            static fn(string $id) => match ($id) {
                'rev-aaa' => $from,
                default => null,
            },
        );

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Revision not found: rev-bbb');

        $this->service->computeDiff('rev-aaa', 'rev-bbb');
    }

    #[Test]
    public function computeDiffHandlesNullToNullUnchanged(): void
    {
        $from = $this->makeRevision(
            id: 'rev-aaa',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
        );

        $to = $this->makeRevision(
            id: 'rev-bbb',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
        );

        $this->revisionRepository->method('findById')->willReturnCallback(
            static fn(string $id) => match ($id) {
                'rev-aaa' => $from,
                'rev-bbb' => $to,
                default => null,
            },
        );

        $diff = $this->service->computeDiff('rev-aaa', 'rev-bbb');

        self::assertFalse($diff->hasChanges());
    }

    #[Test]
    public function computeDiffDetectsNullToValueChange(): void
    {
        $from = $this->makeRevision(id: 'rev-aaa', excerpt: null);
        $to = $this->makeRevision(id: 'rev-bbb', excerpt: 'New excerpt');

        $this->revisionRepository->method('findById')->willReturnCallback(
            static fn(string $id) => match ($id) {
                'rev-aaa' => $from,
                'rev-bbb' => $to,
                default => null,
            },
        );

        $diff = $this->service->computeDiff('rev-aaa', 'rev-bbb');

        self::assertTrue($diff->hasChanges());

        $excerptChange = $this->findChange($diff, 'excerpt');
        self::assertNull($excerptChange->from);
        self::assertSame('New excerpt', $excerptChange->to);
    }

    private function makeRevision(
        string $id = 'rev-001',
        string $title = 'Title',
        string $slug = 'slug',
        string $body = 'Body',
        ?string $excerpt = 'Excerpt',
        ?string $metaTitle = 'Meta Title',
        ?string $metaDescription = 'Meta Description',
    ): ContentRevision {
        return new ContentRevision(
            id: $id,
            contentId: 'content-001',
            locale: 'en',
            revisionNumber: 1,
            title: $title,
            slug: $slug,
            body: $body,
            excerpt: $excerpt,
            metaTitle: $metaTitle,
            metaDescription: $metaDescription,
            authorId: 'author-001',
            reason: null,
            evidenceHash: 'fakehash',
            createdAt: new DateTimeImmutable('2026-01-01'),
        );
    }

    private function findChange(RevisionDiff $diff, string $field): FieldDiff
    {
        foreach ($diff->changes as $change) {
            if ($change->field === $field) {
                return $change;
            }
        }

        self::fail("No change found for field: {$field}");
    }
}
