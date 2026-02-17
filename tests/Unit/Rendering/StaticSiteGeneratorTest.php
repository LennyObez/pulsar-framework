<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\PageRendererInterface;
use Pulsar\Rendering\StaticSiteConfig;
use Pulsar\Rendering\StaticSiteGenerator;
use Pulsar\Rendering\StaticSiteResult;
use RuntimeException;

#[CoversClass(StaticSiteGenerator::class)]
#[CoversClass(StaticSiteResult::class)]
final class StaticSiteGeneratorTest extends TestCase
{
    #[Test]
    public function render_route_stores_html(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<h1>Home</h1>');

        $ssg = new StaticSiteGenerator(new StaticSiteConfig(), $renderer);
        $html = $ssg->renderRoute('/');

        self::assertSame('<h1>Home</h1>', $html);
        self::assertSame(['/' => '<h1>Home</h1>'], $ssg->pages());
        self::assertSame([], $ssg->errors());
    }

    #[Test]
    public function render_route_returns_null_on_error(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willThrowException(new RuntimeException('Not found'));

        $ssg = new StaticSiteGenerator(new StaticSiteConfig(), $renderer);
        $result = $ssg->renderRoute('/missing');

        self::assertNull($result);
        self::assertSame([], $ssg->pages());
        self::assertCount(1, $ssg->errors());
        self::assertStringContainsString('/missing', $ssg->errors()[0]);
        self::assertStringContainsString('Not found', $ssg->errors()[0]);
    }

    #[Test]
    public function render_all_processes_multiple_paths(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturnCallback(
            fn(string $path) => "<p>{$path}</p>",
        );

        $ssg = new StaticSiteGenerator(new StaticSiteConfig(excludePatterns: []), $renderer);
        $result = $ssg->renderAll(['/', '/about', '/contact']);

        self::assertSame(3, $result->pagesGenerated);
        self::assertSame(['/', '/about', '/contact'], $result->paths);
        self::assertFalse($result->hasErrors());
        self::assertTrue($result->isComplete());
    }

    #[Test]
    public function render_all_excludes_matching_patterns(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturnCallback(
            fn(string $path) => "<p>{$path}</p>",
        );

        $config = new StaticSiteConfig(excludePatterns: ['/api/*', '/admin/*']);
        $ssg = new StaticSiteGenerator($config, $renderer);
        $result = $ssg->renderAll(['/', '/api/users', '/about', '/admin/dashboard']);

        self::assertSame(2, $result->pagesGenerated);
        self::assertSame(['/', '/about'], $result->paths);
    }

    #[Test]
    public function render_all_tracks_errors_alongside_successes(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturnCallback(
            function (string $path): string {
                if ($path === '/broken') {
                    throw new RuntimeException('Render failed');
                }

                return "<p>{$path}</p>";
            },
        );

        $config = new StaticSiteConfig(excludePatterns: []);
        $ssg = new StaticSiteGenerator($config, $renderer);
        $result = $ssg->renderAll(['/', '/broken', '/about']);

        self::assertSame(2, $result->pagesGenerated);
        self::assertTrue($result->hasErrors());
        self::assertFalse($result->isComplete());
        self::assertCount(1, $result->errors);
    }

    #[Test]
    public function render_all_resets_state_between_calls(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $config = new StaticSiteConfig(excludePatterns: []);
        $ssg = new StaticSiteGenerator($config, $renderer);

        $ssg->renderAll(['/a', '/b']);
        self::assertCount(2, $ssg->pages());

        $result = $ssg->renderAll(['/c']);
        self::assertSame(1, $result->pagesGenerated);
        self::assertCount(1, $ssg->pages());
    }

    #[Test]
    public function generate_sitemap_produces_valid_xml(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $config = new StaticSiteConfig(
            baseUrl: 'https://example.com',
            excludePatterns: [],
        );
        $ssg = new StaticSiteGenerator($config, $renderer);
        $ssg->renderAll(['/', '/about']);

        $sitemap = $ssg->generateSitemap();

        self::assertStringContainsString('<?xml version="1.0"', $sitemap);
        self::assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $sitemap);
        self::assertStringContainsString('<url><loc>https://example.com/</loc></url>', $sitemap);
        self::assertStringContainsString('<url><loc>https://example.com/about</loc></url>', $sitemap);
    }

    #[Test]
    public function generate_sitemap_escapes_special_chars_in_urls(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $config = new StaticSiteConfig(
            baseUrl: 'https://example.com',
            excludePatterns: [],
        );
        $ssg = new StaticSiteGenerator($config, $renderer);
        $ssg->renderAll(['/search?q=a&b=c']);

        $sitemap = $ssg->generateSitemap();
        self::assertStringContainsString('&amp;', $sitemap);
        self::assertStringNotContainsString('&b=', $sitemap);
    }

    #[Test]
    public function generate_sitemap_strips_trailing_slash_from_base_url(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $config = new StaticSiteConfig(
            baseUrl: 'https://example.com/',
            excludePatterns: [],
        );
        $ssg = new StaticSiteGenerator($config, $renderer);
        $ssg->renderAll(['/about']);

        $sitemap = $ssg->generateSitemap();
        self::assertStringContainsString('https://example.com/about', $sitemap);
        self::assertStringNotContainsString('https://example.com//about', $sitemap);
    }

    #[Test]
    public function empty_render_produces_empty_sitemap(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $ssg = new StaticSiteGenerator(new StaticSiteConfig(), $renderer);

        $sitemap = $ssg->generateSitemap();
        self::assertStringContainsString('<urlset', $sitemap);
        self::assertStringNotContainsString('<url>', $sitemap);
    }
}
