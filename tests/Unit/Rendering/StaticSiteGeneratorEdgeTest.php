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

/**
 * Edge case tests for StaticSiteGenerator.
 */
#[CoversClass(StaticSiteGenerator::class)]
#[CoversClass(StaticSiteResult::class)]
final class StaticSiteGeneratorEdgeTest extends TestCase
{
    #[Test]
    public function renderRouteReturnsHtmlForValidPath(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<h1>Hello</h1>');

        $gen = new StaticSiteGenerator(new StaticSiteConfig(), $renderer);
        $html = $gen->renderRoute('/');

        self::assertSame('<h1>Hello</h1>', $html);
    }

    #[Test]
    public function renderRouteReturnsNullOnRenderFailure(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willThrowException(new RuntimeException('fail'));

        $gen = new StaticSiteGenerator(new StaticSiteConfig(), $renderer);
        $html = $gen->renderRoute('/broken');

        self::assertNull($html);
        self::assertCount(1, $gen->errors());
        self::assertStringContainsString('/broken', $gen->errors()[0]);
    }

    #[Test]
    public function renderAllSkipsExcludedPaths(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $config = new StaticSiteConfig(excludePatterns: ['/api/*', '/admin/*']);
        $gen = new StaticSiteGenerator($config, $renderer);

        $result = $gen->renderAll(['/home', '/api/users', '/admin/dashboard', '/about']);

        self::assertSame(2, $result->pagesGenerated);
        self::assertContains('/home', $result->paths);
        self::assertContains('/about', $result->paths);
        self::assertNotContains('/api/users', $result->paths);
    }

    #[Test]
    public function renderAllRecordsErrors(): void
    {
        $callCount = 0;
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturnCallback(function (string $path) use (&$callCount): string {
            $callCount++;

            if ($path === '/broken') {
                throw new RuntimeException('render failed');
            }

            return '<p>ok</p>';
        });

        $config = new StaticSiteConfig(excludePatterns: []);
        $gen = new StaticSiteGenerator($config, $renderer);

        $result = $gen->renderAll(['/ok', '/broken', '/fine']);

        self::assertTrue($result->hasErrors());
        self::assertFalse($result->isComplete());
        self::assertSame(2, $result->pagesGenerated);
        self::assertCount(1, $result->errors);
    }

    #[Test]
    public function generateSitemapProducesValidXml(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>page</p>');

        $config = new StaticSiteConfig(
            baseUrl: 'https://example.com',
            excludePatterns: [],
        );
        $gen = new StaticSiteGenerator($config, $renderer);
        $gen->renderAll(['/about', '/contact']);

        $xml = $gen->generateSitemap();

        self::assertStringContainsString('<?xml version="1.0"', $xml);
        self::assertStringContainsString('<urlset', $xml);
        self::assertStringContainsString('https://example.com/about', $xml);
        self::assertStringContainsString('https://example.com/contact', $xml);
    }

    #[Test]
    public function generateSitemapEscapesSpecialCharsInUrl(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>p</p>');

        $config = new StaticSiteConfig(
            baseUrl: 'https://example.com',
            excludePatterns: [],
        );
        $gen = new StaticSiteGenerator($config, $renderer);
        $gen->renderAll(['/search?q=a&b=c']);

        $xml = $gen->generateSitemap();

        self::assertStringContainsString('&amp;', $xml);
    }

    #[Test]
    public function pagesReturnsRenderedPages(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturnCallback(fn(string $p): string => "page:{$p}");

        $config = new StaticSiteConfig(excludePatterns: []);
        $gen = new StaticSiteGenerator($config, $renderer);
        $gen->renderAll(['/a', '/b']);

        $pages = $gen->pages();

        self::assertSame('page:/a', $pages['/a']);
        self::assertSame('page:/b', $pages['/b']);
    }

    #[Test]
    public function staticSiteResultHasErrorsAndIsComplete(): void
    {
        $withErrors = new StaticSiteResult(1, ['/a'], ['error1']);
        self::assertTrue($withErrors->hasErrors());
        self::assertFalse($withErrors->isComplete());

        $noErrors = new StaticSiteResult(2, ['/a', '/b'], []);
        self::assertFalse($noErrors->hasErrors());
        self::assertTrue($noErrors->isComplete());
    }

    #[Test]
    public function renderAllResetsStateOnEachCall(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $config = new StaticSiteConfig(excludePatterns: []);
        $gen = new StaticSiteGenerator($config, $renderer);

        $gen->renderAll(['/a', '/b', '/c']);
        self::assertCount(3, $gen->pages());

        $gen->renderAll(['/x']);
        self::assertCount(1, $gen->pages());
    }

    #[Test]
    public function writeToDiskWritesPagesToOutputDir(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<html>hello</html>');

        $outputDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_ssg_test_' . uniqid();
        $config = new StaticSiteConfig(outputDir: $outputDir, excludePatterns: []);
        $gen = new StaticSiteGenerator($config, $renderer);

        $gen->renderAll(['/']);
        $written = $gen->writeToDisk();

        self::assertSame(1, $written);
        self::assertFileExists($outputDir . DIRECTORY_SEPARATOR . 'index.html');
    }
}
