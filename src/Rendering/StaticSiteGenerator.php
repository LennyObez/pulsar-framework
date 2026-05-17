<?php

declare(strict_types=1);

namespace Pulsar\Rendering;

use NoDiscard;
use Pulsar\Api\Api;
use Throwable;

use function array_keys;
use function count;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Static Site Generator (SSG) for pre-rendering routes to HTML files.
 *
 * Crawls registered routes and renders them to static HTML files
 * for CDN serving. Supports sitemap generation and selective
 * route inclusion/exclusion.
 * @api
 */
#[Api(since: '1.0.0')]
final class StaticSiteGenerator
{
    /** @var array<string, string> Pre-rendered pages (path => HTML) */
    private array $pages = [];

    /** @var list<string> Errors encountered during generation */
    private array $errors = [];

    public function __construct(
        private readonly StaticSiteConfig $config,
        private readonly PageRendererInterface $renderer,
    ) {}

    /**
     * Render a single route to a static HTML string.
     */
    public function renderRoute(string $path): ?string
    {
        try {
            $html = $this->renderer->render($path);
            $this->pages[$path] = $html;

            return $html;
        } catch (Throwable $e) {
            $this->errors[] = sprintf('Failed to render %s: %s', $path, $e->getMessage());

            return null;
        }
    }

    /**
     * Render all provided routes.
     *
     * @param list<string> $paths
     */
    public function renderAll(array $paths): StaticSiteResult
    {
        $this->pages = [];
        $this->errors = [];

        foreach ($paths as $path) {
            if ($this->isExcluded($path)) {
                continue;
            }

            $this->renderRoute($path);
        }

        return new StaticSiteResult(
            pagesGenerated: count($this->pages),
            paths: array_keys($this->pages),
            errors: $this->errors,
        );
    }

    /**
     * Write all rendered pages to disk.
     */
    public function writeToDisk(): int
    {
        $written = 0;

        foreach ($this->pages as $path => $html) {
            $filePath = $this->pathToFile($path);
            $dir = dirname($filePath);

            if (!is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }

            if (file_put_contents($filePath, $html) !== false) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Generate a sitemap XML from rendered pages.
     */
    #[NoDiscard]
    public function generateSitemap(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach (array_keys($this->pages) as $path) {
            $url = rtrim($this->config->baseUrl, '/') . $path;
            $xml .= sprintf("  <url><loc>%s</loc></url>\n", htmlspecialchars($url, ENT_XML1, 'UTF-8'));
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Get all rendered pages.
     *
     * @return array<string, string>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    /**
     * Get errors from the last render pass.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->config->excludePatterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function pathToFile(string $path): string
    {
        $outputDir = rtrim($this->config->outputDir, '/\\');

        if ($path === '/' || $path === '') {
            return $outputDir . DIRECTORY_SEPARATOR . 'index.html';
        }

        $path = trim($path, '/');

        return $outputDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path) . DIRECTORY_SEPARATOR . 'index.html';
    }
}
