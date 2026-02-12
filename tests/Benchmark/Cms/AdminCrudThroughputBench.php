<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Cms;

use DateTimeImmutable;
use Override;
use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\PublishingStateMachine;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\FieldRegistry\ContentFieldValue;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeField;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\ContentController;
use Pulsar\Extension\Cms\Workflow\ContentLock;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialReview;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tests\Benchmark\Cms\Support\CmsBenchmarkFactory;
use Pulsar\Tests\Benchmark\Cms\Support\NullAuditLogger;
use RuntimeException;

/**
 * Admin CRUD throughput benchmark.
 *
 * Measures admin ContentController create and update operations
 * with in-memory repositories. Target: p95 < 300 microseconds.
 */
#[BeforeMethods('setUp')]
#[Revs(100)]
#[Iterations(5)]
#[Warmup(1)]
final class AdminCrudThroughputBench
{
    private ContentController $controller;
    private IdentityInterface $identity;

    public function setUp(): void
    {
        $factory = new CmsBenchmarkFactory();
        $contentId = $factory->generateUuidV7();
        $content = $factory->createPublishedContent(id: $contentId);
        $translation = $factory->createTranslation($contentId, slug: 'admin-bench');

        $contentRepo = new class ($content) implements ContentRepositoryInterface {
            public function __construct(private readonly Content $content) {}

            #[Override]
            public function findById(string $id): Content
            {
                return $this->content;
            }

            #[Override]
            public function findByPath(string $locale, string $path, ?string $tenantId = null): Content
            {
                return $this->content;
            }

            #[Override]
            public function findPublished(string $locale, ?string $contentType = null, int $page = 1, int $perPage = 20, ?string $tenantId = null): PaginationResult
            {
                return new PaginationResult(items: [$this->content], total: 1, hasMore: false, perPage: $perPage);
            }

            #[Override]
            public function findByIds(array $ids): array
            {
                return [$this->content->id => $this->content];
            }

            #[Override]
            public function findAncestors(string $contentId, int $maxDepth = 20): array
            {
                return [];
            }

            #[Override]
            public function save(Content $content): void {}

            #[Override]
            public function delete(Content $content): void {}

            #[Override]
            public function findDescendants(string $contentId): array
            {
                return [];
            }

            #[Override]
            public function findScheduledForPublishing(DateTimeImmutable $now): array
            {
                return [];
            }

            #[Override]
            public function findScheduledForUnpublishing(DateTimeImmutable $now): array
            {
                return [];
            }

            #[Override]
            public function bulkUpdateStatus(array $ids, \Pulsar\Extension\Cms\Content\PublishingStatus $status, ?string $tenantId = null): int
            {
                return 0;
            }

            #[Override]
            public function bulkDelete(array $ids, ?string $tenantId = null): int
            {
                return 0;
            }
        };

        $translationRepo = new class ($translation) implements ContentTranslationRepositoryInterface {
            public function __construct(private readonly ContentTranslation $translation) {}

            #[Override]
            public function findById(string $id): ContentTranslation
            {
                return $this->translation;
            }

            #[Override]
            public function findByPath(string $locale, string $path, ?string $tenantId = null): ContentTranslation
            {
                return $this->translation;
            }

            #[Override]
            public function findByContentAndLocale(string $contentId, string $locale): ContentTranslation
            {
                return $this->translation;
            }

            #[Override]
            public function findByContentId(string $contentId): array
            {
                return [$this->translation];
            }

            #[Override]
            public function findByContentIds(array $contentIds): array
            {
                $result = [];

                foreach ($contentIds as $id) {
                    $result[$id] = [$this->translation];
                }

                return $result;
            }

            #[Override]
            public function save(ContentTranslation $translation): void {}

            #[Override]
            public function delete(string $id): void {}
        };

        $blockRepo = new class implements ContentBlockRepositoryInterface {
            #[Override]
            public function findById(string $id): ?ContentBlock
            {
                return null;
            }

            #[Override]
            public function findByContentAndLocale(string $contentId, string $locale): array
            {
                return [];
            }

            #[Override]
            public function save(ContentBlock $block): void {}

            #[Override]
            public function saveAll(array $blocks): void {}

            #[Override]
            public function delete(string $id): void {}

            #[Override]
            public function deleteByContentAndLocale(string $contentId, string $locale): void {}
        };

        $fieldRepo = new class implements FieldRegistryRepositoryInterface {
            #[Override]
            public function findFieldsByContentType(string $contentType): array
            {
                return [];
            }

            #[Override]
            public function saveField(ContentTypeField $field): void {}

            #[Override]
            public function saveValue(ContentFieldValue $value): void {}

            #[Override]
            public function findValues(string $contentId, ?string $locale = null): array
            {
                return [];
            }
        };

        $gate = new class implements GateInterface {
            #[Override]
            public function allows(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
            {
                return true;
            }

            #[Override]
            public function denies(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
            {
                return false;
            }
        };

        $lockService = new class implements ContentLockServiceInterface {
            #[Override]
            public function acquire(string $contentId, string $userId, ?string $locale = null): ?ContentLock
            {
                return null;
            }

            #[Override]
            public function release(string $contentId, string $userId): void {}

            #[Override]
            public function heartbeat(string $contentId, string $userId): ?ContentLock
            {
                return null;
            }

            #[Override]
            public function forceUnlock(string $contentId, ?string $actorId = null): void {}

            #[Override]
            public function isLocked(string $contentId, ?string $locale = null): ?ContentLock
            {
                return null;
            }

            #[Override]
            public function getLockInfo(string $contentId): ?ContentLock
            {
                return null;
            }

            #[Override]
            public function cleanupExpired(): int
            {
                return 0;
            }
        };

        $workflowService = new class implements EditorialWorkflowServiceInterface {
            #[Override]
            public function submitForReview(string $contentId, string $requestedBy, ?string $locale = null, ?string $reviewerId = null): EditorialReview
            {
                throw new RuntimeException('Not implemented for benchmark');
            }

            #[Override]
            public function approve(string $reviewId, string $decisionReason): EditorialReview
            {
                throw new RuntimeException('Not implemented for benchmark');
            }

            #[Override]
            public function reject(string $reviewId, string $decisionReason, ?string $comment = null): EditorialReview
            {
                throw new RuntimeException('Not implemented for benchmark');
            }

            #[Override]
            public function getPendingReviews(?string $reviewerId = null): array
            {
                return [];
            }

            #[Override]
            public function cancelReview(string $reviewId): EditorialReview
            {
                throw new RuntimeException('Not implemented for benchmark');
            }
        };

        $safeHtmlPolicy = new SafeHtmlPolicy(new NullAuditLogger());
        $publishingStateMachine = new PublishingStateMachine();
        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'fr']);

        $this->identity = new Identity(
            id: 'bench-admin-1',
            displayName: 'Benchmark Admin',
            roles: ['cms.admin'],
        );

        $this->controller = new ContentController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
            blockRepository: $blockRepo,
            fieldRepository: $fieldRepo,
            publishingStateMachine: $publishingStateMachine,
            lockService: $lockService,
            workflowService: $workflowService,
            safeHtmlPolicy: $safeHtmlPolicy,
            gate: $gate,
            config: $config,
        );
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 1 millisecond')]
    public function benchAdminContentCreate(): void
    {
        $request = new ServerRequest(method: 'POST', uri: '/admin/content')
            ->withAttribute('identity', $this->identity)
            ->withParsedBody([
                'content_type' => 'article',
                'locale' => 'en',
                'title' => 'Benchmark Article',
                'slug' => 'benchmark-article',
                'body' => '<p>Benchmark article body content.</p>',
            ]);

        $response = $this->controller->create($request);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 1 millisecond')]
    public function benchAdminContentUpdate(): void
    {
        $request = new ServerRequest(method: 'PUT', uri: '/admin/content/test-id')
            ->withAttribute('identity', $this->identity)
            ->withParsedBody([
                'locale' => 'en',
                'title' => 'Updated Benchmark Article',
                'body' => '<p>Updated benchmark article body content.</p>',
            ]);

        $response = $this->controller->update($request, 'test-id');
    }
}
