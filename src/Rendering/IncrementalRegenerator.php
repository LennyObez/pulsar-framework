<?php

declare(strict_types=1);

namespace Pulsar\Rendering;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_keys;
use function array_merge;
use function array_unique;
use function count;
use function time;

/**
 * Incremental Static Regeneration (ISR).
 *
 * Serves stale cached pages immediately and regenerates in background
 * on a configurable interval. Supports tag-based invalidation so that
 * content changes trigger regeneration of affected pages.
 * @api
 */
#[Api(since: '1.0.0')]
final class IncrementalRegenerator
{
    /** @var array<string, CachedPage> */
    private array $cache = [];

    /** @var array<string, list<string>> Tag → paths mapping */
    private array $tagIndex = [];

    public function __construct(
        private readonly PageRendererInterface $renderer,
        private readonly int $revalidateAfterSeconds = 60,
    ) {}

    /**
     * Get a page, serving stale if available and marking for background regeneration.
     *
     * @param list<string> $tags Cache tags for invalidation
     */
    public function getPage(string $path, array $tags = []): IsrResult
    {
        $now = time();

        if (isset($this->cache[$path])) {
            $cached = $this->cache[$path];
            $isStale = ($now - $cached->generatedAt) > $this->revalidateAfterSeconds;

            return new IsrResult(
                html: $cached->html,
                hit: true,
                stale: $isStale,
                path: $path,
            );
        }

        $html = $this->regenerate($path, $tags);

        return new IsrResult(
            html: $html,
            hit: false,
            stale: false,
            path: $path,
        );
    }

    /**
     * Regenerate a specific page.
     *
     * @param list<string> $tags
     */
    public function regenerate(string $path, array $tags = []): string
    {
        $html = $this->renderer->render($path);
        $this->cache[$path] = new CachedPage($html, time());

        foreach ($tags as $tag) {
            $this->tagIndex[$tag] = array_values(array_unique(
                array_merge($this->tagIndex[$tag] ?? [], [$path]),
            ));
        }

        return $html;
    }

    /**
     * Invalidate all pages tagged with the given tag.
     *
     * @return list<string> Paths that were invalidated
     */
    #[NoDiscard]
    public function invalidateTag(string $tag): array
    {
        $paths = $this->tagIndex[$tag] ?? [];

        foreach ($paths as $path) {
            unset($this->cache[$path]);
        }

        unset($this->tagIndex[$tag]);

        return $paths;
    }

    /**
     * Invalidate a specific path.
     */
    public function invalidatePath(string $path): void
    {
        unset($this->cache[$path]);
    }

    /**
     * Get all stale paths that need regeneration.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function stalePaths(): array
    {
        $now = time();

        return array_keys(array_filter(
            $this->cache,
            fn(CachedPage $page) => ($now - $page->generatedAt) > $this->revalidateAfterSeconds,
        ));
    }

    /**
     * Get the number of cached pages.
     */
    public function cacheSize(): int
    {
        return count($this->cache);
    }
}
