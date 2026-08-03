<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Persistence;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentRepository;
use TypeError;

#[CoversClass(DbContentRepository::class)]
final class DbContentRepositoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function contentRow(array $overrides = []): array
    {
        return array_merge([
            'id' => '019568a1-0000-7000-8000-000000000001',
            'tenant_id' => null,
            'content_type' => 'article',
            'author_id' => 'author-01',
            'status' => 'draft',
            'scheduled_publish_at' => null,
            'scheduled_unpublish_at' => null,
            'published_at' => null,
            'created_at' => '2025-01-15T10:00:00+00:00',
            'updated_at' => '2025-01-15T10:00:00+00:00',
            'deleted_at' => null,
            'template' => null,
            'parent_id' => null,
            'sort_order' => 0,
            'comment_policy' => 'inherit',
            'data_classification' => 'public',
            'version' => 1,
        ], $overrides);
    }

    // ── findById ──────────────────────────────────────────────────────

    #[Test]
    public function findByIdReturnsContentWhenFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(Result::fromArrays([$this->contentRow()]));

        $repo = new DbContentRepository($db, null);
        $result = $repo->findById('019568a1-0000-7000-8000-000000000001');

        self::assertInstanceOf(Content::class, $result);
        self::assertSame('019568a1-0000-7000-8000-000000000001', $result->id);
        self::assertSame(ContentType::Article, $result->contentType);
        self::assertSame(PublishingStatus::Draft, $result->status);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbContentRepository($db, null);

        self::assertNull($repo->findById('nonexistent'));
    }

    // ── findByPath ───────────────────────────────────────────────────

    #[Test]
    public function findByPathReturnsContentWhenFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(Result::fromArrays([$this->contentRow()]));

        $repo = new DbContentRepository($db, null);
        $result = $repo->findByPath('en', '/docs');

        self::assertInstanceOf(Content::class, $result);
    }

    #[Test]
    public function findByPathReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbContentRepository($db, null);

        self::assertNull($repo->findByPath('en', '/missing'));
    }

    // ── findPublished ────────────────────────────────────────────────

    #[Test]
    public function findPublishedReturnsPaginatedResults(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 1]]),
            Result::fromArrays([$this->contentRow(['status' => 'published'])]),
        );

        $repo = new DbContentRepository($db, null);
        $result = $repo->findPublished('en');

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
        self::assertSame(1, $result->currentPage);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function findPublishedWithContentTypeFilterApplied(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbContentRepository($db, null);
        $result = $repo->findPublished('en', contentType: 'article');

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    #[Test]
    public function findPublishedClampsPageToMinimumOfOne(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbContentRepository($db, null);
        $result = $repo->findPublished('en', page: -5);

        self::assertSame(1, $result->currentPage);
    }

    #[Test]
    public function findPublishedWithMultiplePagesHasMore(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 30]]),
            Result::fromArrays([$this->contentRow(['status' => 'published'])]),
        );

        $repo = new DbContentRepository($db, null);
        $result = $repo->findPublished('en', page: 1, perPage: 10);

        self::assertSame(30, $result->total);
        self::assertTrue($result->hasMore);
        self::assertSame(3, $result->lastPage);
    }

    // ── findByIds ────────────────────────────────────────────────────

    #[Test]
    public function findByIdsReturnsEmptyForEmptyInput(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);

        $repo = new DbContentRepository($db, null);

        self::assertSame([], $repo->findByIds([]));
    }

    #[Test]
    public function findByIdsReturnsIndexedByContentId(): void
    {
        $id = '019568a1-0000-7000-8000-000000000001';
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(Result::fromArrays([$this->contentRow(['id' => $id])]));

        $repo = new DbContentRepository($db, null);
        $result = $repo->findByIds([$id]);

        self::assertArrayHasKey($id, $result);
        self::assertInstanceOf(Content::class, $result[$id]);
    }

    #[Test]
    public function findByIdsThrowsOnInvalidUuid(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $repo = new DbContentRepository($db, null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid UUID');

        $repo->findByIds(['not-a-uuid']);
    }

    #[Test]
    public function findByIdsThrowsOnNonStringId(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $repo = new DbContentRepository($db, null);

        $this->expectException(TypeError::class);

        /** @phpstan-ignore argument.type */
        $repo->findByIds([123]);
    }

    // ── findAncestors ────────────────────────────────────────────────

    #[Test]
    public function findAncestorsReturnsHydratedContent(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(Result::fromArrays([
            $this->contentRow(['id' => '019568a1-0000-7000-8000-000000000002']),
        ]));

        $repo = new DbContentRepository($db, null);
        $result = $repo->findAncestors('019568a1-0000-7000-8000-000000000001');

        self::assertCount(1, $result);
        self::assertSame('019568a1-0000-7000-8000-000000000002', $result[0]->id);
    }

    // ── save ─────────────────────────────────────────────────────────

    #[Test]
    public function saveThrowsConcurrencyConflictWhenNoRowsAffected(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('execute')->willReturn(0);

        $repo = new DbContentRepository($db, null);
        $content = Content::create(
            id: '019568a1-0000-7000-8000-000000000001',
            contentType: ContentType::Article,
            authorId: 'author-01',
        );

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('Concurrency conflict');

        $repo->save($content);
    }

    #[Test]
    public function saveSucceedsWhenRowAffected(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('execute')->willReturn(1);

        $repo = new DbContentRepository($db, null);
        $content = Content::create(
            id: '019568a1-0000-7000-8000-000000000001',
            contentType: ContentType::Article,
            authorId: 'author-01',
        );

        $this->expectNotToPerformAssertions();

        $repo->save($content);
    }

    // ── delete ───────────────────────────────────────────────────────

    #[Test]
    public function deleteSoftDeletesContent(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('execute')->willReturn(1);

        $repo = new DbContentRepository($db, null);
        $content = Content::create(
            id: '019568a1-0000-7000-8000-000000000001',
            contentType: ContentType::Page,
            authorId: 'author-01',
        );

        $this->expectNotToPerformAssertions();

        $repo->delete($content);
    }

    // ── findDescendants ──────────────────────────────────────────────

    #[Test]
    public function findDescendantsReturnsHydratedContent(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(Result::fromArrays([
            $this->contentRow(['id' => '019568a1-0000-7000-8000-000000000003', 'parent_id' => '019568a1-0000-7000-8000-000000000001']),
        ]));

        $repo = new DbContentRepository($db, null);
        $result = $repo->findDescendants('019568a1-0000-7000-8000-000000000001');

        self::assertCount(1, $result);
    }

    // ── findScheduledForPublishing / Unpublishing ────────────────────

    #[Test]
    public function findScheduledForPublishingReturnsResults(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(Result::fromArrays([
            $this->contentRow(['status' => 'scheduled', 'scheduled_publish_at' => '2025-01-15T10:00:00+00:00']),
        ]));

        $repo = new DbContentRepository($db, null);
        $result = $repo->findScheduledForPublishing(new DateTimeImmutable());

        self::assertCount(1, $result);
    }

    #[Test]
    public function findScheduledForUnpublishingReturnsResults(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(Result::fromArrays([
            $this->contentRow(['status' => 'published', 'scheduled_unpublish_at' => '2025-01-15T10:00:00+00:00']),
        ]));

        $repo = new DbContentRepository($db, null);
        $result = $repo->findScheduledForUnpublishing(new DateTimeImmutable());

        self::assertCount(1, $result);
    }

    // ── bulkUpdateStatus ─────────────────────────────────────────────

    #[Test]
    public function bulkUpdateStatusReturnsZeroForEmptyIds(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $repo = new DbContentRepository($db, null);

        self::assertSame(0, $repo->bulkUpdateStatus([], PublishingStatus::Published));
    }

    #[Test]
    public function bulkUpdateStatusExecutesForValidIds(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('execute')->willReturn(2);

        $repo = new DbContentRepository($db, null);
        $result = $repo->bulkUpdateStatus([
            '019568a1-0000-7000-8000-000000000001',
            '019568a1-0000-7000-8000-000000000002',
        ], PublishingStatus::Published);

        self::assertSame(2, $result);
    }

    #[Test]
    public function bulkUpdateStatusWithTenantId(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('execute')->willReturn(1);

        $repo = new DbContentRepository($db, null);
        $result = $repo->bulkUpdateStatus(
            ['019568a1-0000-7000-8000-000000000001'],
            PublishingStatus::Archived,
            tenantId: 'tenant-01',
        );

        self::assertSame(1, $result);
    }

    #[Test]
    public function bulkUpdateStatusThrowsOnInvalidUuid(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $repo = new DbContentRepository($db, null);

        $this->expectException(InvalidArgumentException::class);

        $repo->bulkUpdateStatus(['bad-id'], PublishingStatus::Published);
    }

    // ── bulkDelete ───────────────────────────────────────────────────

    #[Test]
    public function bulkDeleteReturnsZeroForEmptyIds(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $repo = new DbContentRepository($db, null);

        self::assertSame(0, $repo->bulkDelete([]));
    }

    #[Test]
    public function bulkDeleteExecutesForValidIds(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('execute')->willReturn(1);

        $repo = new DbContentRepository($db, null);
        $result = $repo->bulkDelete(['019568a1-0000-7000-8000-000000000001']);

        self::assertSame(1, $result);
    }

    #[Test]
    public function bulkDeleteWithTenantId(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('execute')->willReturn(1);

        $repo = new DbContentRepository($db, null);
        $result = $repo->bulkDelete(
            ['019568a1-0000-7000-8000-000000000001'],
            tenantId: 'tenant-01',
        );

        self::assertSame(1, $result);
    }

    #[Test]
    public function bulkDeleteThrowsOnInvalidUuid(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $repo = new DbContentRepository($db, null);

        $this->expectException(InvalidArgumentException::class);

        $repo->bulkDelete(['not-valid']);
    }

    // ── hydrate edge cases ───────────────────────────────────────────

    #[Test]
    public function hydratesContentWithAllFieldsPopulated(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(Result::fromArrays([$this->contentRow([
            'tenant_id' => 'tenant-01',
            'status' => 'published',
            'scheduled_publish_at' => '2025-02-01T00:00:00+00:00',
            'scheduled_unpublish_at' => '2025-03-01T00:00:00+00:00',
            'published_at' => '2025-01-20T10:00:00+00:00',
            'deleted_at' => '2025-04-01T00:00:00+00:00',
            'template' => 'blog-post',
            'parent_id' => '019568a1-0000-7000-8000-000000000099',
            'sort_order' => 5,
            'comment_policy' => 'open',
            'data_classification' => 'pii',
            'version' => 3,
        ])]));

        $repo = new DbContentRepository($db, null);
        $result = $repo->findById('019568a1-0000-7000-8000-000000000001');

        self::assertNotNull($result);
        self::assertSame('tenant-01', $result->tenantId);
        self::assertSame(PublishingStatus::Published, $result->status);
        self::assertNotNull($result->scheduledPublishAt);
        self::assertNotNull($result->scheduledUnpublishAt);
        self::assertNotNull($result->publishedAt);
        self::assertNotNull($result->deletedAt);
        self::assertSame('blog-post', $result->template);
        self::assertSame('019568a1-0000-7000-8000-000000000099', $result->parentId);
        self::assertSame(5, $result->sortOrder);
        self::assertSame(CommentPolicy::Open, $result->commentPolicy);
        self::assertSame(DataClassification::Pii, $result->dataClassification);
        self::assertSame(3, $result->version);
    }
}
