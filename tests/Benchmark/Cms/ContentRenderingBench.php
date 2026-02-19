<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Cms;

use Override;
use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\FieldRegistry\ContentFieldValue;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeField;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Http\Controller\ContentController;
use Pulsar\Extension\Cms\I18n\HreflangGenerator;
use Pulsar\Extension\Cms\I18n\LocaleResolver;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGeneratorInterface;
use Pulsar\Extension\Cms\Navigation\BreadcrumbItem;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tests\Benchmark\Cms\Support\CmsBenchmarkFactory;

/**
 * Content rendering pipeline benchmark.
 *
 * Measures the full ContentController::show() path with in-memory repositories:
 * path resolution, translation lookup, block loading, field loading,
 * breadcrumb generation, hreflang generation, and HTML rendering.
 *
 * Isolates controller logic from I/O to measure pure computation cost.
 */
#[BeforeMethods('setUp')]
#[Revs(100)]
#[Iterations(5)]
#[Warmup(1)]
final class ContentRenderingBench
{
    private ContentController $controller;
    private ServerRequest $request;

    public function setUp(): void
    {
        $factory = new CmsBenchmarkFactory();
        $contentId = $factory->generateUuidV7();
        $content = $factory->createPublishedContent(id: $contentId);
        $translation = $factory->createTranslation($contentId, slug: 'benchmark-article');
        $blocks = $factory->createBlocks($contentId, count: 5);
        $fieldValues = $factory->createFieldValues($contentId);
        $breadcrumbs = $factory->createBreadcrumbs();

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

        $blockRepo = new class ($blocks) implements ContentBlockRepositoryInterface {
            /** @param list<ContentBlock> $blocks */
            public function __construct(private readonly array $blocks) {}

            #[Override]
            public function findById(string $id): ?ContentBlock
            {
                return null;
            }

            #[Override]
            public function findByContentAndLocale(string $contentId, string $locale): array
            {
                return $this->blocks;
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

        $redirectRepo = new class implements RedirectRepositoryInterface {
            #[Override]
            public function findByPath(string $path, ?string $locale = null, ?string $tenantId = null): ?Redirect
            {
                return null;
            }

            #[Override]
            public function save(Redirect $redirect): void {}

            #[Override]
            public function incrementHits(string $redirectId): void {}

            #[Override]
            public function findAll(int $page = 1, int $perPage = 50, ?string $tenantId = null): array
            {
                return [];
            }

            #[Override]
            public function delete(string $redirectId): void {}
        };

        $fieldRepo = new class ($fieldValues) implements FieldRegistryRepositoryInterface {
            /** @param list<ContentFieldValue> $values */
            public function __construct(private readonly array $values) {}

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
                return $this->values;
            }
        };

        $breadcrumbGen = new class ($breadcrumbs) implements BreadcrumbGeneratorInterface {
            /** @param list<BreadcrumbItem> $items */
            public function __construct(private readonly array $items) {}

            #[Override]
            public function generate(Content $content, string $locale): array
            {
                return $this->items;
            }
        };

        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr', 'de'],
        );

        $localeResolver = new LocaleResolver();
        $hreflangGen = new HreflangGenerator($translationRepo, $localeResolver);

        $this->controller = new ContentController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
            blockRepository: $blockRepo,
            redirectRepository: $redirectRepo,
            fieldRepository: $fieldRepo,
            breadcrumbGenerator: $breadcrumbGen,
            hreflangGenerator: $hreflangGen,
            config: $config,
        );

        $this->request = new ServerRequest(method: 'GET', uri: '/benchmark-article');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 500 microseconds')]
    public function benchContentShowHtml(): void
    {
        $response = $this->controller->show($this->request);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 500 microseconds')]
    public function benchContentShowJson(): void
    {
        $request = $this->request->withHeader('Accept', 'application/json');
        $response = $this->controller->show($request);
    }
}
