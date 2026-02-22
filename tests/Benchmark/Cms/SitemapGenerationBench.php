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
use Pulsar\Extension\Cms\Http\Controller\SitemapController;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;
use Pulsar\Http\Message\ServerRequest;

use function sprintf;

/**
 * Sitemap generation benchmark.
 *
 * Measures SitemapController response time with a synthetic sitemap generator
 * that produces XML for a configurable number of content items.
 * Target: < 5 seconds for 10,000 items (generator layer).
 */
#[BeforeMethods('setUp')]
#[Revs(10)]
#[Iterations(5)]
#[Warmup(1)]
final class SitemapGenerationBench
{
    private SitemapController $controller;
    private ServerRequest $request;

    public function setUp(): void
    {
        $generator = new class implements SitemapGeneratorInterface {
            #[Override]
            public function generateIndex(string $baseUrl, ?string $tenantId = null): string
            {
                $xml = '<?xml version="1.0" encoding="UTF-8"?>';
                $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

                for ($i = 1; $i <= 20; $i++) {
                    $xml .= sprintf(
                        '<sitemap><loc>%s/sitemap-article-%d.xml</loc></sitemap>',
                        $baseUrl,
                        $i,
                    );
                }

                $xml .= '</sitemapindex>';

                return $xml;
            }

            #[Override]
            public function generateForType(string $contentType, string $baseUrl, int $page = 1, ?string $tenantId = null): string
            {
                $itemsPerPage = 500;
                $xml = '<?xml version="1.0" encoding="UTF-8"?>';
                $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

                for ($i = 0; $i < $itemsPerPage; $i++) {
                    $index = (($page - 1) * $itemsPerPage) + $i;
                    $xml .= sprintf(
                        '<url><loc>%s/%s/%s-item-%d</loc><lastmod>2026-02-19</lastmod><changefreq>weekly</changefreq><priority>0.7</priority></url>',
                        $baseUrl,
                        $contentType,
                        $contentType,
                        $index,
                    );
                }

                $xml .= '</urlset>';

                return $xml;
            }
        };

        $this->controller = new SitemapController($generator);
        $this->request = new ServerRequest(method: 'GET', uri: '/sitemap.xml');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 milliseconds')]
    public function benchSitemapIndex(): void
    {
        $response = $this->controller->index($this->request);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 milliseconds')]
    public function benchSitemapForType500Items(): void
    {
        $response = $this->controller->forType($this->request, 'article', 1);
    }

    /**
     * Benchmark generating sitemaps covering 10,000 items (20 pages of 500).
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 seconds')]
    public function benchSitemap10kItems(): void
    {
        for ($page = 1; $page <= 20; $page++) {
            $response = $this->controller->forType($this->request, 'article', $page);
        }
    }
}
