<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Seo;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Seo\Event\LinkHealthCheckCompleted;
use Pulsar\Extension\Cms\Seo\LinkHealthCheck;
use Pulsar\Extension\Cms\Seo\LinkHealthRepositoryInterface;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;

use function array_map;
use function array_unique;
use function in_array;
use function preg_match_all;

use const PREG_SET_ORDER;

/**
 * Checks link health by extracting URLs from content bodies and verifying HTTP responses.
 */
#[Internal(reason: 'Use LinkHealthServiceInterface for public API')]
final readonly class LinkHealthChecker implements LinkHealthServiceInterface
{
    /** Total timeout for link checks (seconds). */
    private const int REQUEST_TIMEOUT = 30;

    /** HTTP status codes that indicate a broken link. */
    private const array BROKEN_STATUS_CODES = [400, 403, 404, 405, 410, 500, 502, 503, 504];

    /** HTTP status codes that indicate a redirect. */
    private const array REDIRECT_STATUS_CODES = [301, 302, 303, 307, 308];

    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private LinkHealthRepositoryInterface $linkHealthRepository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private CmsConfig $config,
    ) {}

    public function checkAll(?string $tenantId = null): array
    {
        $results = [];
        $totalChecked = 0;
        $brokenCount = 0;

        foreach ($this->config->supportedLocales as $locale) {
            $page = 1;

            do {
                $paginatedContent = $this->contentRepository->findPublished(
                    locale: $locale,
                    page: $page,
                    perPage: 100,
                    tenantId: $tenantId,
                );

                // Batch-load translations for this page to avoid N+1
                $contentIds = array_map(static fn(Content $c): string => $c->id, $paginatedContent->items);
                $translationsByContentId = $this->translationRepository->findByContentIds($contentIds);

                /** @var Content $content */
                foreach ($paginatedContent->items as $content) {
                    $translations = $translationsByContentId[$content->id] ?? [];
                    $translation = null;

                    foreach ($translations as $t) {
                        if ($t->locale === $locale) {
                            $translation = $t;

                            break;
                        }
                    }

                    if ($translation === null) {
                        continue;
                    }

                    $urls = $this->extractUrls($translation->body);

                    // Clear previous results for this content/locale
                    $this->linkHealthRepository->deleteByContent($content->id, $locale);

                    foreach ($urls as $url) {
                        $check = $this->checkUrl($url, $content->id, $locale, $content->tenantId);
                        $this->linkHealthRepository->save($check);
                        $results[] = $check;
                        $totalChecked++;

                        if ($check->isBroken) {
                            $brokenCount++;
                        }
                    }
                }

                $page++;
            } while ($paginatedContent->items !== [] && $page <= (int) ceil($paginatedContent->total / 100));
        }

        $this->eventDispatcher->dispatch(new LinkHealthCheckCompleted(
            totalChecked: $totalChecked,
            brokenCount: $brokenCount,
        ));

        $this->logger->info('Link health check completed', [
            'total_checked' => $totalChecked,
            'broken_count' => $brokenCount,
        ]);

        return $results;
    }

    public function checkContent(Content $content, string $locale): array
    {
        $translation = $this->translationRepository->findByContentAndLocale($content->id, $locale);

        if ($translation === null) {
            return [];
        }

        $urls = $this->extractUrls($translation->body);

        // Clear previous results for this content/locale
        $this->linkHealthRepository->deleteByContent($content->id, $locale);

        $results = [];

        foreach ($urls as $url) {
            $check = $this->checkUrl($url, $content->id, $locale, $content->tenantId);
            $this->linkHealthRepository->save($check);
            $results[] = $check;
        }

        return $results;
    }

    public function getBrokenLinks(?string $tenantId = null, int $page = 1, int $perPage = 50): array
    {
        return $this->linkHealthRepository->findBroken($tenantId, $page, $perPage);
    }

    public function getOrphanContent(?string $tenantId = null, int $page = 1, int $perPage = 50): array
    {
        // Orphan detection: find published content with no inbound internal links.
        $allPublished = $this->contentRepository->findPublished(
            locale: $this->config->defaultLocale,
            page: $page,
            perPage: $perPage,
            tenantId: $tenantId,
        );

        // Batch-load link health records for all content items on this page
        $contentIds = array_map(static fn(Content $c): string => $c->id, $allPublished->items);
        $linkChecksByContentId = $this->linkHealthRepository->findByContentIds(
            $contentIds,
            $this->config->defaultLocale,
        );

        $orphans = [];

        /** @var Content $content */
        foreach ($allPublished->items as $content) {
            $inbound = $linkChecksByContentId[$content->id] ?? [];

            if ($inbound === []) {
                $orphans[] = $content;
            }
        }

        return $orphans;
    }

    /**
     * Extract all URLs from HTML content.
     *
     * @return list<string>
     */
    private function extractUrls(string $html): array
    {
        $urls = [];

        // Match href and src attributes
        if (preg_match_all('/(?:href|src)=["\']([^"\']+)["\']/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = $match[1];

                // Only check HTTP(S) URLs
                if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                    $urls[] = $url;
                }
            }
        }

        return array_unique($urls);
    }

    /**
     * Check a single URL and return a LinkHealthCheck record.
     */
    private function checkUrl(string $url, string $contentId, string $locale, ?string $tenantId): LinkHealthCheck
    {
        $now = new DateTimeImmutable();
        $statusCode = null;
        $isBroken = false;
        $isRedirected = false;

        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => self::REQUEST_TIMEOUT,
                'follow_location' => 0,
                'ignore_errors' => true,
                'header' => "User-Agent: Pulsar-LinkChecker/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $headers = @get_headers($url, context: $context);

        if ($headers === false) {
            $isBroken = true;
        } else {
            // Parse the HTTP status code from the first header line
            $statusLine = $headers[0] ?? '';

            if (preg_match('/HTTP\/[\d.]+\s+(\d{3})/', $statusLine, $m)) {
                $statusCode = (int) $m[1];
                $isBroken = in_array($statusCode, self::BROKEN_STATUS_CODES, true);
                $isRedirected = in_array($statusCode, self::REDIRECT_STATUS_CODES, true);
            } else {
                $isBroken = true;
            }
        }

        return new LinkHealthCheck(
            id: UuidGenerator::v7(),
            tenantId: $tenantId,
            sourceContentId: $contentId,
            sourceLocale: $locale,
            targetUrl: $url,
            isBroken: $isBroken,
            isRedirected: $isRedirected,
            httpStatusCode: $statusCode,
            lastCheckedAt: $now,
            createdAt: $now,
        );
    }
}
