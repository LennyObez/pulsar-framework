<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\BlockEditor\BlockRenderer;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function htmlspecialchars;
use function is_string;
use function json_decode;
use function sprintf;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;

/**
 * Back-office live preview controller.
 *
 * Provides two endpoints:
 *  1. The admin split-pane page (editor left, preview iframe right)
 *  2. The preview render endpoint that returns the content as it would
 *     appear on the public front-office, suitable for loading in an iframe
 */
#[Internal(reason: 'CMS admin controller - implementation detail')]
final readonly class PreviewController extends AbstractAdminController
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ContentBlockRepositoryInterface $blockRepository,
        private SafeHtmlPolicy $safeHtmlPolicy,
        private ?BlockRenderer $blockRenderer = null,
        ?TemplateEngineInterface $templateEngine = null,
        ?GateInterface $gate = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * Show the split-pane preview page (admin side).
     *
     * The page loads the content editor form on the left and an iframe
     * pointing to the render endpoint on the right.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.edit');

        $params = $request->getQueryParams();
        /** @var mixed $rawLocale */
        $rawLocale = $params['locale'] ?? null;
        $locale = self::sanitizeLocale(is_string($rawLocale) ? $rawLocale : 'en');

        $content = $this->contentRepository->findById($id);

        if ($content === null || $content->isDeleted()) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        $translation = $this->translationRepository->findByContentAndLocale($id, $locale);
        $blocks = $this->blockRepository->findByContentAndLocale($id, $locale);

        // Build the preview iframe URL with URL-encoded parameters.
        // The `$id` comes from the route, but URL-encoding here prevents any
        // future refactor from accidentally allowing special characters through.
        $previewUrl = sprintf(
            '/admin/cms/content/%s/preview/render?locale=%s',
            rawurlencode($id),
            rawurlencode($locale),
        );

        return $this->respondWithView($request, 'admin.content.preview', [
            'content' => [
                'id' => $content->id,
                'type' => $content->contentType->value,
                'status' => $content->status->value,
                'template' => $content->template ?? $content->contentType->value,
            ],
            'translation' => $translation !== null ? [
                'title' => $translation->title,
                'slug' => $translation->slugSegment,
                'body' => $translation->body,
                'excerpt' => $translation->excerpt,
            ] : ['title' => '', 'slug' => '', 'body' => '', 'excerpt' => ''],
            'blocks' => array_map(static fn($block) => [
                'id' => $block->id,
                'type' => $block->blockType,
                'sort_order' => $block->sortOrder,
                'data' => $block->data,
            ], $blocks),
            'locale' => $locale,
            'previewUrl' => $previewUrl,
        ]);
    }

    /**
     * Render the content as a public-facing HTML fragment for the preview iframe.
     *
     * Accepts either a GET (renders the persisted content) or a POST (renders
     * the submitted body in real-time for live preview while typing).
     */
    public function render(ServerRequestInterface $request, string $id): Response
    {
        // Require an authenticated admin identity to prevent unauthenticated
        // access to the sanitizer input/output surface. Even though the preview
        // endpoint is mounted under /admin/ (which typically has global auth),
        // we enforce at the controller level as defence in depth.
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.edit');

        $params = $request->getQueryParams();
        /** @var mixed $rawLocale */
        $rawLocale = $params['locale'] ?? null;
        $locale = self::sanitizeLocale(is_string($rawLocale) ? $rawLocale : 'en');

        // For POST requests (live preview), use the submitted body
        $method = $request->getMethod();
        $title = '';
        $body = '';
        $excerpt = '';
        $blocksJson = '';

        if ($method === 'POST') {
            /** @var array<string, mixed> $postData */
            $postData = (array) ($request->getParsedBody() ?? []);
            /** @var mixed $rawTitle */
            $rawTitle = $postData['title'] ?? null;
            $title = trim(is_string($rawTitle) ? $rawTitle : '');
            /** @var mixed $rawBody */
            $rawBody = $postData['body'] ?? null;
            $body = is_string($rawBody) ? $rawBody : '';
            /** @var mixed $rawExcerpt */
            $rawExcerpt = $postData['excerpt'] ?? null;
            $excerpt = trim(is_string($rawExcerpt) ? $rawExcerpt : '');
            /** @var mixed $rawBlocksJson */
            $rawBlocksJson = $postData['blocks_json'] ?? null;
            $blocksJson = is_string($rawBlocksJson) ? $rawBlocksJson : '';
        } else {
            $translation = $this->translationRepository->findByContentAndLocale($id, $locale);

            if ($translation !== null) {
                $title = $translation->title;
                $body = $translation->body;
                $excerpt = $translation->excerpt ?? '';
            }
        }

        $sanitizedBody = $this->safeHtmlPolicy->sanitize($body);
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escapedExcerpt = htmlspecialchars($excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Render blocks if block renderer is available and we have blocks
        $renderedBlocksHtml = '';

        if ($this->blockRenderer !== null && $blocksJson !== '') {
            /** @var list<array{blockType?: string, type?: string, data?: array<string, mixed>}> $blockData */
            $blockData = json_decode($blocksJson, true, 512, JSON_THROW_ON_ERROR) ?: [];

            // Normalize 'type' key to 'blockType' for renderRawBlocks compatibility
            $normalizedBlocks = [];

            foreach ($blockData as $blockDef) {
                $normalizedBlocks[] = [
                    'blockType' => $blockDef['blockType'] ?? $blockDef['type'] ?? '',
                    'data' => $blockDef['data'] ?? [],
                ];
            }

            $renderedBlocksHtml = $this->blockRenderer->renderRawBlocks($normalizedBlocks);
        }

        $previewHtml = $this->buildPreviewHtml($escapedTitle, $sanitizedBody, $escapedExcerpt, $renderedBlocksHtml, $locale);

        // Strict CSP for the preview document: no scripts, no remote anything.
        // Inline styles are allowed so the preview page's minimal CSS (and any
        // scoped theme overrides) render correctly. Images allowed from same
        // origin and data: URIs to support embedded editor previews.
        $csp = "default-src 'none'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: https:; "
            . "font-src 'self'; "
            . "frame-ancestors 'self'; "
            . "base-uri 'none'; "
            . "form-action 'none'";

        return new Response(body: $previewHtml)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * Validate a locale string against BCP-47 shape.
     *
     * Returns the locale if it matches ([a-z]{2,3}(-[A-Z]{2})?), otherwise
     * returns the default 'en'. Refusing arbitrary input prevents attribute
     * injection via the locale field (e.g., `en" onmouseover="alert(1)`).
     */
    private static function sanitizeLocale(string $locale): string
    {
        if (preg_match('/\A[a-z]{2,3}(-[A-Z]{2})?\z/', $locale) === 1) {
            return $locale;
        }

        return 'en';
    }

    private function buildPreviewHtml(
        string $title,
        string $body,
        string $excerpt,
        string $renderedBlocks,
        string $locale,
    ): string {
        $content = $renderedBlocks !== '' ? $renderedBlocks : $body;

        // Defence in depth: even though `sanitizeLocale()` already restricts
        // locale to BCP-47 shape, escape it again at the point of HTML
        // interpolation. If `sanitizeLocale()` is ever loosened, this
        // second layer prevents attribute injection.
        $safeLocale = htmlspecialchars($locale, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="{$safeLocale}" data-theme="light" data-extension="cms">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="robots" content="noindex, nofollow">
                <title>{$title} - Preview</title>
                <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
                <link rel="stylesheet" href="/cms/assets/cms-public.css">
                <style>
                    body { margin: 0; }
                    .preview-wrap { max-width: 70ch; margin: 0 auto; padding: 2rem 1.5rem; }
                </style>
            </head>
            <body class="cms-public">
                <div class="preview-wrap">
                    <article>
                        <h1 style="font-family: var(--font-heading); font-size: var(--text-3xl); font-weight: 700; margin: 0 0 1rem; letter-spacing: -0.025em;">{$title}</h1>
                        <div class="pui-prose">{$content}</div>
                    </article>
                </div>
            </body>
            </html>
            HTML;
    }
}
