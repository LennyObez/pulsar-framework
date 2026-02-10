<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Cms;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Tests\Benchmark\Cms\Support\CmsBenchmarkFactory;
use RuntimeException;

use function memory_get_peak_usage;

/**
 * Memory budget benchmark.
 *
 * Measures peak memory consumption for CMS domain object creation
 * in isolation (no I/O). Verifies that in-memory data structures
 * stay within budget for typical workloads.
 *
 * Target: < 32MB for public request data, < 64MB for admin request data.
 */
#[BeforeMethods('setUp')]
#[Revs(1)]
#[Iterations(3)]
#[Warmup(0)]
final class MemoryBudgetBench
{
    private CmsBenchmarkFactory $factory;

    public function setUp(): void
    {
        $this->factory = new CmsBenchmarkFactory();
    }

    /**
     * Simulate building a public page response data set:
     * 1 content + 1 translation + 5 blocks + 2 field values + 3 breadcrumbs + search result.
     *
     * Budget: < 2 MB peak.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 seconds')]
    public function benchPublicPageMemory(): void
    {
        $before = memory_get_peak_usage(true);

        $contentId = $this->factory->generateUuidV7();
        $content = $this->factory->createPublishedContent(id: $contentId);
        $translation = $this->factory->createTranslation($contentId);
        $blocks = $this->factory->createBlocks($contentId, count: 5);
        $fields = $this->factory->createFieldValues($contentId);
        $breadcrumbs = $this->factory->createBreadcrumbs();

        $after = memory_get_peak_usage(true);
        $delta = $after - $before;

        // 2 MB budget for public page data
        if ($delta > 2_097_152) {
            throw new RuntimeException(
                "Public page memory budget exceeded: {$delta} bytes (budget: 2MB)",
            );
        }
    }

    /**
     * Simulate building an admin listing response:
     * 100 content items with pagination metadata.
     *
     * Budget: < 8 MB peak.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 seconds')]
    public function benchAdminListingMemory(): void
    {
        $before = memory_get_peak_usage(true);

        $paginated = $this->factory->createPaginatedContent(count: 100, total: 5000);

        $after = memory_get_peak_usage(true);
        $delta = $after - $before;

        // 8 MB budget for admin listing
        if ($delta > 8_388_608) {
            throw new RuntimeException(
                "Admin listing memory budget exceeded: {$delta} bytes (budget: 8MB)",
            );
        }
    }

    /**
     * Simulate building a large search result:
     * 100 search results.
     *
     * Budget: < 4 MB peak.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 seconds')]
    public function benchSearchResultMemory(): void
    {
        $before = memory_get_peak_usage(true);

        $searchResult = $this->factory->createSearchResult(itemCount: 100);

        $after = memory_get_peak_usage(true);
        $delta = $after - $before;

        // 4 MB budget for search result set
        if ($delta > 4_194_304) {
            throw new RuntimeException(
                "Search result memory budget exceeded: {$delta} bytes (budget: 4MB)",
            );
        }
    }

    /**
     * Simulate building a large import bundle in memory:
     * 1000-item JSON bundle string.
     *
     * Budget: < 16 MB peak (JSON string + parsed array).
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 10 seconds')]
    public function benchImportBundleMemory(): void
    {
        $before = memory_get_peak_usage(true);

        $bundle = $this->factory->generateImportBundle(1000);
        $parsed = json_decode($bundle, true, 512, JSON_THROW_ON_ERROR);

        $after = memory_get_peak_usage(true);
        $delta = $after - $before;

        // 16 MB budget for import bundle
        if ($delta > 16_777_216) {
            throw new RuntimeException(
                "Import bundle memory budget exceeded: {$delta} bytes (budget: 16MB)",
            );
        }
    }
}
