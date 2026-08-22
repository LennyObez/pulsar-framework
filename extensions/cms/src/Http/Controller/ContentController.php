<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Extension\Cms\BlockEditor\BlockRenderer;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Http\Middleware\CmsPageCacheMiddleware;
use Pulsar\Extension\Cms\I18n\HreflangGenerator;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheKeys;
use Pulsar\Extension\Cms\Internal\Persistence\SchemaErrors;
use Pulsar\Extension\Cms\Internal\Security\CmsKeyManager;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGeneratorInterface;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Seo\SeoServiceInterface;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function assert;
use function count;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function hash;
use function hash_equals;
use function hash_hmac;
use function htmlspecialchars;
use function in_array;
use function is_string;
use function json_encode;
use function ltrim;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function time;

use const ENT_HTML5;
use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Public-facing content rendering controller.
 *
 * Resolves content by locale and path, verifies publishing status,
 * loads the full aggregate (translations, blocks, custom fields,
 * taxonomy terms), resolves the template, and returns the rendered response.
 */
#[Internal(reason: 'CMS HTTP controller; implementation detail')]
final readonly class ContentController
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ContentBlockRepositoryInterface $blockRepository,
        private RedirectRepositoryInterface $redirectRepository,
        private FieldRegistryRepositoryInterface $fieldRepository,
        private BreadcrumbGeneratorInterface $breadcrumbGenerator,
        private HreflangGenerator $hreflangGenerator,
        private CmsKeyManager $keyManager,
        private SafeHtmlPolicy $safeHtmlPolicy,
        private CmsConfig $config,
        private ?SeoServiceInterface $seoService = null,
        private ?TemplateEngineInterface $templateEngine = null,
        private ?BlockRenderer $blockRenderer = null,
        private ?MenuRepositoryInterface $menuRepository = null,
        private ?ThemeRepositoryInterface $themeRepository = null,
        private string $projectViewsPath = '',
    ) {}

    public function show(ServerRequestInterface $request): Response
    {
        try {
            return $this->resolve($request);
        } catch (DatabaseException $e) {
            if (!SchemaErrors::isMissingTable($e)) {
                // A genuine database error (an outage, a permission problem, a
                // malformed query) must stay visible as a 500 — never masked
                // into a 404 that would hide a real failure.
                throw $e;
            }

            // The CMS schema is not installed (a fresh / unmigrated database).
            // Because the CMS owns the catch-all route, a missing schema must
            // degrade gracefully instead of turning the homepage AND every
            // unmatched route into a 500: greet the root with the welcome page,
            // and return a 404 for anything else.
            $rawPath = ltrim($request->getUri()->getPath(), '/');
            $isRoot = $rawPath === '' || $rawPath === $this->config->defaultLocale;

            return $isRoot
                ? Response::html($this->renderWelcomePage())
                : $this->respondNotFound($request);
        }
    }

    /**
     * Resolve the response for a content request.
     *
     * May touch the CMS schema (redirects, translations, content, blocks); when
     * that schema is absent {@see show()} maps the resulting DatabaseException to
     * a graceful fallback rather than a 500.
     */
    private function resolve(ServerRequestInterface $request): Response
    {
        $rawPath = ltrim($request->getUri()->getPath(), '/');

        // Resolve the active locale from middleware attributes.
        //
        // Two middlewares may set a locale: the core LocalePrefixMiddleware /
        // LocaleMiddleware set `_locale` (from the URL prefix when present, else
        // from an Accept-Language header or cookie), and CmsLocaleMiddleware sets
        // `cms_locale` (extracted from the URL path prefix, or the configured
        // default when the path carries no prefix).
        //
        // The most explicit signal is a locale that appears in the URL itself.
        // So when the request path still carries a `{cms_locale}/` prefix, that
        // CMS locale wins over a `_locale` that may have come from a header or
        // cookie. Otherwise (the global middleware already stripped the prefix,
        // or `cms_locale` is merely the default) fall back to `_locale`, then the
        // configured default.
        /** @var string|null $coreLocale */
        $coreLocale = $request->getAttribute('_locale');

        /** @var string|null $cmsLocale */
        $cmsLocale = $request->getAttribute('cms_locale');

        if ($cmsLocale !== null && $cmsLocale !== '' && str_starts_with($rawPath, $cmsLocale . '/')) {
            $locale = $cmsLocale;
        } else {
            $locale = $coreLocale ?? $cmsLocale ?? $this->config->defaultLocale;
        }

        if (!in_array($locale, $this->config->supportedLocales, true)) {
            $locale = $this->config->defaultLocale;
        }

        $contentPath = $rawPath;

        // Strip locale prefix from path if still present (when global middleware didn't strip it)
        if ($rawPath !== '') {
            $prefix = $locale . '/';

            if (str_starts_with($contentPath, $prefix)) {
                $contentPath = substr($contentPath, strlen($prefix));
            } elseif ($contentPath === $locale) {
                $contentPath = '';
            }
        }

        // When all locales are in the URL (default_locale_in_url=true) and the user
        // hits bare /, redirect to /{defaultLocale}/. This only applies when no global
        // middleware has already extracted a locale (which would mean the path was stripped).
        if ($contentPath === '' && $this->config->defaultLocaleInUrl && $coreLocale === null && $cmsLocale === null) {
            return Response::redirect('/' . $this->config->defaultLocale . '/', 302);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        // Fallback redirect check (middleware handles primary, this is a safety net)
        $redirect = $this->redirectRepository->findByPath($contentPath, $locale, $tenantId);

        if ($redirect !== null) {
            $this->redirectRepository->incrementHits($redirect->id);
            $targetUrl = $redirect->toPath;

            if (!str_starts_with($targetUrl, 'http://') && !str_starts_with($targetUrl, 'https://')) {
                $uri = $request->getUri();
                $targetUrl = $uri->getScheme() . '://' . $uri->getHost() . '/' . ltrim($targetUrl, '/');
            }

            return Response::redirect($targetUrl, $redirect->statusCode);
        }

        // Resolve content by path (or explicit homepage ID for root)
        $translation = null;

        if ($contentPath === '' && $this->config->homepageContentId !== null) {
            // Explicit homepage: resolve by content ID + locale
            $translation = $this->translationRepository->findByContentAndLocale(
                $this->config->homepageContentId,
                $locale,
            );
        }

        if ($translation === null) {
            $translation = $this->translationRepository->findByPath($locale, $contentPath, $tenantId);
        }

        // For root path: try empty-path lookup before falling back to the welcome page.
        // This ensures that content with an empty slug (homepage) is served even when
        // no explicit homepage_content_id is configured.
        if ($translation === null && $contentPath === '') {
            $translation = $this->translationRepository->findByPath($locale, '', $tenantId);
        }

        if ($translation === null) {
            // Show a styled welcome page for the root path when no content exists at all
            if ($contentPath === '') {
                return Response::html($this->renderWelcomePage());
            }

            return $this->respondNotFound($request);
        }

        $content = $this->contentRepository->findById($translation->contentId);

        if ($content === null || $content->isDeleted()) {
            return $this->respondNotFound($request);
        }

        // Verify content is published (or preview token is valid)
        /** @var mixed $previewToken */
        $previewToken = $request->getQueryParams()['preview_token'] ?? null;

        if (!$content->isPublished() && !$this->isValidPreviewToken($previewToken, $content->id)) {
            return $this->respondNotFound($request);
        }

        // Load aggregate
        $blocks = $this->blockRepository->findByContentAndLocale($content->id, $locale);
        $customFields = $this->fieldRepository->findValues($content->id, $locale);
        $breadcrumbs = $this->breadcrumbGenerator->generate($content, $locale);

        // Resolve template
        $template = $content->template ?? $content->contentType->value;

        // Build response data
        $responseData = [
            'content' => [
                'id' => $content->id,
                'type' => $content->contentType->value,
                'status' => $content->status->value,
                'author_id' => $content->authorId,
                'published_at' => $content->publishedAt?->format('c'),
                'created_at' => $content->createdAt->format('c'),
                'updated_at' => $content->updatedAt->format('c'),
                'template' => $template,
            ],
            'translation' => [
                'locale' => $translation->locale,
                'title' => $translation->title,
                'slug' => $translation->slugSegment,
                'path' => $translation->path,
                'body' => $translation->body,
                'excerpt' => $translation->excerpt,
                'meta_title' => $translation->metaTitle,
                'meta_description' => $translation->metaDescription,
                'reading_time_minutes' => $translation->readingTimeMinutes,
            ],
            'blocks' => array_map(static fn($block) => [
                'id' => $block->id,
                'type' => $block->blockType,
                'sort_order' => $block->sortOrder,
                'data' => $block->data,
            ], $blocks),
            'custom_fields' => array_map(static fn($field) => [
                'field_id' => $field->fieldId,
                'locale' => $field->locale,
                'value_string' => $field->valueString,
                'value_int' => $field->valueInt,
                'value_float' => $field->valueFloat,
                'value_bool' => $field->valueBool,
                'value_datetime' => $field->valueDatetime?->format('c'),
                'value_json' => $field->valueJson,
            ], $customFields),
            'breadcrumbs' => array_map(static fn($item) => [
                'label' => $item->label,
                'url' => $item->url,
                'is_current' => $item->isCurrent,
            ], $breadcrumbs),
            'hreflang' => array_map(static fn($link) => [
                'locale' => $link->locale,
                'href' => $link->href,
            ], $this->hreflangGenerator->generate($content, $locale, $this->config)),
        ];

        $isJson = $request->getHeaderLine('Accept') === 'application/json'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($isJson) {
            $responseBody = json_encode($responseData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $response = Response::json($responseData);
        } else {
            $uri = $request->getUri();
            $baseUrl = $uri->getScheme() . '://' . $uri->getHost();

            $port = $uri->getPort();

            if ($port !== null && $port !== 80 && $port !== 443) {
                $baseUrl .= ':' . $port;
            }

            // Expose the request CSP nonce (set by a strict nonce-based CSP policy,
            // if any) so script-emitting blocks like the contact-form managed-
            // challenge widget can stamp it; null when no nonce policy is active.
            /** @var mixed $cspNonce */
            $cspNonce = $request->getAttribute('csp_nonce');
            $responseData['_csp_nonce'] = is_string($cspNonce) ? $cspNonce : null;
            $responseBody = $this->renderHtml($responseData, $template, $content, $translation, $baseUrl);
            // Declare this page's fine-grained cache tags for the page-cache
            // middleware (internal header, stripped before the response leaves
            // the server) so publishing this content or its type invalidates
            // exactly the pages that rendered it.
            $response = Response::html($responseBody)->withHeader(
                CmsPageCacheMiddleware::TAGS_HEADER,
                CmsCacheKeys::contentTag($content->id) . ',' . CmsCacheKeys::typeTag($content->contentType->value),
            );
        }

        return $this->applyCacheHeaders($request, $response, $responseBody, $content, $previewToken);
    }

    private function applyCacheHeaders(
        ServerRequestInterface $request,
        Response $response,
        string $responseBody,
        Content $content,
        mixed $previewToken,
    ): Response {
        $isPreview = $previewToken !== null && $this->isValidPreviewToken($previewToken, $content->id);

        if ($isPreview) {
            return $response
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
                ->withHeader('Pragma', 'no-cache');
        }

        $ttl = $this->config->httpCacheTtlSeconds;
        $etag = '"' . hash('xxh3', $responseBody) . '"';
        $lastModified = $content->updatedAt->format('D, d M Y H:i:s') . ' GMT';

        // Check for conditional request: If-None-Match
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');

        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return new Response(statusCode: 304, headers: [
                'ETag' => $etag,
                'Cache-Control' => "public, max-age=$ttl, s-maxage=$ttl",
            ]);
        }

        // Check for conditional request: If-Modified-Since
        $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');

        if ($ifModifiedSince !== '') {
            $clientDate = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $ifModifiedSince);

            if ($clientDate !== false && $content->updatedAt <= $clientDate) {
                return new Response(statusCode: 304, headers: [
                    'Last-Modified' => $lastModified,
                    'Cache-Control' => "public, max-age=$ttl, s-maxage=$ttl",
                ]);
            }
        }

        return $response
            ->withHeader('Cache-Control', "public, max-age=$ttl, s-maxage=$ttl")
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', $lastModified);
    }

    public function generatePreviewToken(string $contentId, int $ttlSeconds = 3600): string
    {
        $expiryTimestamp = time() + $ttlSeconds;
        $signature = hash_hmac('sha256', $contentId . '|' . $expiryTimestamp, $this->keyManager->previewKey());

        return $expiryTimestamp . '.' . $signature;
    }

    private function isValidPreviewToken(mixed $token, string $contentId): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$expiryTimestamp, $signature] = $parts;
        $expiryTimestamp = (int) $expiryTimestamp;

        if ($expiryTimestamp <= 0 || $expiryTimestamp <= time()) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $contentId . '|' . $expiryTimestamp, $this->keyManager->previewKey());

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Return an appropriate 404 response based on content negotiation.
     */
    private function respondNotFound(ServerRequestInterface $request): Response
    {
        $accept = $request->getHeaderLine('Accept');

        if ($accept === 'application/json' || str_contains($accept, 'application/json')) {
            return Response::json(
                ['error' => 'Content not found', 'status' => 404],
                404,
            );
        }

        // Render HTML 404 page via template engine when available
        $engine = $this->templateEngine ?? Response::getTemplateEngine();

        if ($engine !== null) {
            $locale = 'en';
            $menuItems = [];

            if ($this->menuRepository !== null) {
                try {
                    $menu = $this->menuRepository->findByLocation('primary', $locale);

                    if ($menu !== null) {
                        $items = $this->menuRepository->findItemsByMenu($menu->id, $locale);

                        foreach ($items as $item) {
                            $menuItems[] = [
                                'label' => $item->label,
                                'url' => $item->url ?? ($item->contentPath !== null ? '/' . ltrim($item->contentPath, '/') : '#'),
                                'children' => [],
                            ];
                        }
                    }
                } catch (DatabaseException $e) {
                    // Render the 404 without navigation when the CMS schema is
                    // absent (a fresh / unmigrated database). A genuine DB error
                    // still surfaces rather than being masked.
                    if (!SchemaErrors::isMissingTable($e)) {
                        throw $e;
                    }

                    $menuItems = [];
                }
            }

            $html = $engine->render('cms::public.pages.not-found', [
                'menuItems' => $menuItems,
                'config' => $this->config,
                'locale' => $locale,
                'siteName' => $this->config->siteName,
            ]);

            return Response::html($html, 404);
        }

        // Inline HTML fallback when no template engine is available
        return Response::html(
            <<<'HTML'
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <meta name="robots" content="noindex">
                    <title>Page Not Found</title>
                    <style>
                        body{font-family:system-ui,-apple-system,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f8fafc;color:#1e293b}
                        .error{text-align:center;max-width:480px;padding:2rem}
                        h1{font-size:3rem;margin:0 0 .5rem}
                        p{color:#64748b;margin:0 0 1.5rem}
                        a{color:#2563eb;text-decoration:none;font-weight:600}
                        a:hover{text-decoration:underline}
                        a:focus-visible{outline:2px solid #2563eb;outline-offset:2px;border-radius:0.25rem}
                    </style>
                </head>
                <body>
                    <a href="#main-content" class="sr-only sr-only--focusable" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">Skip to main content</a>
                    <main id="main-content" class="error" role="main">
                        <h1>404</h1>
                        <p>The page you are looking for does not exist or has been moved.</p>
                        <a href="/">Back to Homepage</a>
                    </main>
                </body>
                </html>
                HTML,
            404,
        );
    }

    private function renderWelcomePage(): string
    {
        $templatePath = dirname(__DIR__, 3) . '/resources/views/welcome.pulse.php';
        $content = file_get_contents($templatePath);

        if ($content === false) {
            return '<html><body><h1>Welcome to Pulsar CMS</h1></body></html>';
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderHtml(
        array $data,
        string $template,
        ?Content $content = null,
        ?ContentTranslation $translationObj = null,
        string $baseUrl = '',
    ): string {
        // Use Pulse template engine when available.
        // Prefer the constructor-injected engine, but fall back to the static engine
        // on Response (which the Kernel updates after extensions boot with all view paths).
        $engine = $this->templateEngine ?? \Pulsar\Http\Message\Response::getTemplateEngine();

        if ($engine !== null) {
            return $this->renderWithPulse($data, $template, $content, $translationObj, $baseUrl, $engine);
        }

        // Fallback to inline HTML when no template engine is available
        /** @var array<string, mixed> $translation */
        $translation = $data['translation'] ?? [];
        /** @var mixed $rawTitle */
        $rawTitle = $translation['title'] ?? null;
        /** @var mixed $rawBody */
        $rawBody = $translation['body'] ?? null;
        /** @var mixed $rawMetaTitle */
        $rawMetaTitle = $translation['meta_title'] ?? null;
        /** @var mixed $rawMetaDescription */
        $rawMetaDescription = $translation['meta_description'] ?? null;
        $title = htmlspecialchars(is_string($rawTitle) ? $rawTitle : '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = $this->safeHtmlPolicy->sanitize(is_string($rawBody) ? $rawBody : '');
        $metaTitle = htmlspecialchars(is_string($rawMetaTitle) ? $rawMetaTitle : $title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $metaDescription = htmlspecialchars(is_string($rawMetaDescription) ? $rawMetaDescription : '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Build hreflang link tags
        /** @var list<array{locale: string, href: string}> $hreflangLinks */
        $hreflangLinks = $data['hreflang'] ?? [];
        $hreflangHtml = '';

        foreach ($hreflangLinks as $link) {
            $hreflang = htmlspecialchars($link['locale'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $href = htmlspecialchars($link['href'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $hreflangHtml .= "\n        <link rel=\"alternate\" hreflang=\"$hreflang\" href=\"$href\">";
        }

        // Build JSON-LD structured data
        $jsonLdHtml = '';

        if ($this->seoService !== null && $content !== null && $translationObj !== null) {
            $structuredData = $this->seoService->generateStructuredData($content, $translationObj, $baseUrl);
            $jsonLdHtml = $structuredData->toScript();

            if ($jsonLdHtml !== '') {
                $jsonLdHtml = "\n        " . $jsonLdHtml;
            }
        }

        /** @var list<array{label: string, url: string, is_current: bool}> $breadcrumbs */
        $breadcrumbs = $data['breadcrumbs'] ?? [];
        $breadcrumbHtml = '';

        foreach ($breadcrumbs as $crumb) {
            $label = htmlspecialchars($crumb['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $url = htmlspecialchars($crumb['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($crumb['is_current']) {
                $breadcrumbHtml .= "<span aria-current=\"page\">$label</span>";
            } else {
                $breadcrumbHtml .= "<a href=\"$url\">$label</a> <span aria-hidden=\"true\">&raquo;</span> ";
            }
        }

        /** @var mixed $rawLocale */
        $rawLocale = $translation['locale'] ?? null;
        $locale = htmlspecialchars(is_string($rawLocale) ? $rawLocale : 'en', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Build canonical URL and Open Graph tags
        /** @var mixed $rawPath */
        $rawPath = $translation['path'] ?? null;
        $path = is_string($rawPath) ? $rawPath : '';
        $canonicalUrl = htmlspecialchars($baseUrl . '/' . ltrim($path, '/'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ogTitle = $metaTitle;
        $ogDescription = $metaDescription;
        $ogSiteName = htmlspecialchars($this->config->siteName, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Build RSS feed discovery link
        $feedDiscovery = "\n        <link rel=\"alternate\" type=\"application/rss+xml\" title=\"$ogSiteName RSS Feed\" href=\"$baseUrl/feed/rss\">";

        return <<<HTML
            <!DOCTYPE html>
            <html lang="$locale">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>$metaTitle</title>
                <meta name="description" content="$metaDescription">
                <link rel="canonical" href="$canonicalUrl">
                <meta property="og:type" content="article">
                <meta property="og:title" content="$ogTitle">
                <meta property="og:description" content="$ogDescription">
                <meta property="og:url" content="$canonicalUrl">
                <meta property="og:site_name" content="$ogSiteName">$hreflangHtml$jsonLdHtml$feedDiscovery
            </head>
            <body>
                <a href="#main-content" class="sr-only sr-only--focusable">Skip to main content</a>
                <nav aria-label="Breadcrumb">$breadcrumbHtml</nav>
                <main id="main-content">
                    <article>
                        <h1>$title</h1>
                        <div class="content-body">$body</div>
                    </article>
                </main>
            </body>
            </html>
            HTML;
    }

    /**
     * Render content using the Pulse template engine with front-office templates.
     *
     * @param array<string, mixed> $data
     */
    private function renderWithPulse(
        array $data,
        string $template,
        ?Content $content = null,
        ?ContentTranslation $translationObj = null,
        string $baseUrl = '',
        ?TemplateEngineInterface $engine = null,
    ): string {
        // Render blocks to HTML
        $renderedBlocks = '';

        /** @var list<mixed> $blockList */
        $blockList = $data['blocks'] ?? [];

        if ($this->blockRenderer !== null && $blockList !== []) {
            /** @var mixed $cspNonce */
            $cspNonce = $data['_csp_nonce'] ?? null;
            $renderedBlocks = $this->blockRenderer->renderRawBlocks(
                $blockList,
                is_string($cspNonce) ? $cspNonce : null,
            );
        }

        // Load navigation menu items for the layout
        $menuItems = [];
        $locale = $translationObj !== null ? $translationObj->locale : 'en';

        if ($this->menuRepository !== null) {
            $menu = $this->menuRepository->findByLocation('primary', $locale);

            if ($menu !== null) {
                $items = $this->menuRepository->findItemsByMenu($menu->id, $locale);
                $isDefaultLocale = $locale === $this->config->defaultLocale && !$this->config->defaultLocaleInUrl;

                foreach ($items as $item) {
                    $href = $item->url;

                    if ($href === null && $item->contentPath !== null) {
                        $path = '/' . ltrim($item->contentPath, '/');
                        $href = $isDefaultLocale ? $path : '/' . $locale . $path;
                    }

                    $menuItems[] = [
                        'label' => $item->label,
                        'url' => $href ?? '#',
                        'children' => [],
                    ];
                }
            }
        }

        // Resolve the Pulse template with priority: project > active theme > CMS defaults.
        //
        // 1. If the project has resources/views/{template}.pulse.php, use it
        // 2. If the active theme provides the template, use it
        // 3. Fall back to the CMS default template mapping
        $templateName = $this->resolveTemplateName($template);

        // Build SEO data
        $seoData = [];

        if ($this->seoService !== null && $content !== null && $translationObj !== null) {
            $structuredData = $this->seoService->generateStructuredData($content, $translationObj, $baseUrl);
            $seoData['jsonLd'] = $structuredData->toScript();
        }

        $renderEngine = $engine ?? $this->templateEngine;
        assert($renderEngine !== null);

        // Convenience variables extracted from the translation object for direct
        // template access (avoids $translation->metaTitle in every layout)
        $metaTitle = $translationObj !== null && $translationObj->metaTitle !== ''
            ? $translationObj->metaTitle
            : ($translationObj->title ?? '');
        $metaDescription = $translationObj !== null ? $translationObj->metaDescription : '';

        // Derive section from content path (first segment, e.g., "development" from "development/projects")
        $contentPath = $translationObj !== null ? $translationObj->path : '';
        $section = str_contains($contentPath, '/') ? strstr($contentPath, '/', true) : ($contentPath !== '' ? $contentPath : 'landing');

        // Build hreflang as a simple locale => URL map for template use
        $hreflangMap = [];

        /** @var array<int, array{locale: string, url: string}> $hreflangEntries */
        $hreflangEntries = $data['hreflang'] ?? [];

        foreach ($hreflangEntries as $entry) {
            if (isset($entry['locale'], $entry['url'])) {
                $hreflangMap[$entry['locale']] = $entry['url'];
            }
        }

        $canonicalUrl = $baseUrl . '/' . ltrim($contentPath, '/');

        return $renderEngine->render($templateName, [
            'content' => $content,
            'translation' => $translationObj,
            'blocks' => $data['blocks'] ?? [],
            'renderedBlocks' => $renderedBlocks,
            'customFields' => $data['custom_fields'] ?? [],
            'breadcrumbs' => $data['breadcrumbs'] ?? [],
            'hreflang' => $hreflangEntries,
            'hreflangs' => $hreflangMap,
            'menuItems' => $menuItems,
            'seo' => $seoData,
            'config' => $this->config,
            'locale' => $translationObj !== null ? $translationObj->locale : 'en',
            'siteName' => $this->config->siteName,
            'metaTitle' => $metaTitle ?? '',
            'metaDescription' => $metaDescription,
            'section' => $section,
            'year' => (int) date('Y'),
            'baseUrl' => $baseUrl,
            'currentUrl' => $canonicalUrl,
            'canonicalUrl' => $canonicalUrl,
            'ogImage' => ($translationObj !== null && $translationObj->ogImageId !== null) ? '/media/' . $translationObj->ogImageId : null,
            'jsonLd' => $seoData['jsonLd'] ?? '',
        ]);
    }

    /**
     * Resolve a template name with priority: project > active theme > CMS defaults.
     *
     * If the template contains "::" it is already fully qualified and is returned
     * as-is. Otherwise the resolver checks the project views directory for a
     * matching .pulse.php file, then the active theme templates directory, and
     * finally falls back to CMS default template mapping.
     */
    private function resolveTemplateName(string $template): string
    {
        // Fully-qualified Pulse template reference (e.g., "cms::public.pages.landing")
        if (str_contains($template, '::')) {
            return $template;
        }

        // 1. Project override: search multiple path patterns in resources/views/
        if ($this->projectViewsPath !== '') {
            $viewsRoot = rtrim($this->projectViewsPath, '/\\');

            // Try exact path: resources/views/{template}.pulse.php
            // e.g., template="pages/home" -> resources/views/pages/home.pulse.php
            $candidates = [
                $viewsRoot . '/' . $template . '.pulse.php',
                $viewsRoot . '/theme/' . $template . '.pulse.php',
                $viewsRoot . '/' . $template . '/index.pulse.php',
                $viewsRoot . '/theme/' . $template . '/index.pulse.php',
            ];

            // Also try with the last segment only (strip path prefix)
            // e.g., template="pages/about" -> resources/views/about.pulse.php
            $lastSegment = basename($template);
            if ($lastSegment !== $template) {
                $candidates[] = $viewsRoot . '/' . $lastSegment . '.pulse.php';
                $candidates[] = $viewsRoot . '/theme/' . $lastSegment . '.pulse.php';
                $candidates[] = $viewsRoot . '/' . $lastSegment . '/index.pulse.php';
                $candidates[] = $viewsRoot . '/theme/' . $lastSegment . '/index.pulse.php';
            }

            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
        }

        // 2. Active theme override: {theme_storage}/templates/{template}.pulse.php
        if ($this->themeRepository !== null) {
            $activeTheme = $this->themeRepository->findActive();

            if ($activeTheme !== null) {
                $themePath = rtrim($activeTheme->storagePath, '/\\');
                $themeFile = $themePath . '/templates/' . $template . '.pulse.php';

                if (file_exists($themeFile)) {
                    return $themeFile;
                }

                // Try last segment only
                $lastSegment = basename($template);
                if ($lastSegment !== $template) {
                    $themeFile = $themePath . '/templates/' . $lastSegment . '.pulse.php';
                    if (file_exists($themeFile)) {
                        return $themeFile;
                    }
                }
            }
        }

        // 3. CMS default mapping: a bare short name maps to the matching CMS
        // front-office template under the cms:: namespace (e.g. "portfolio" ->
        // "cms::public.pages.portfolio"). Path-style names use their last
        // segment; an empty name falls back to the generic page template.
        $lastSegment = basename($template);

        if ($lastSegment === '') {
            return 'cms::public.pages.page';
        }

        return 'cms::public.pages.' . $lastSegment;
    }
}
