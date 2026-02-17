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
final readonly class PreviewController
{
    use RendersAdminView;

    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ContentBlockRepositoryInterface $blockRepository,
        private SafeHtmlPolicy $safeHtmlPolicy,
        private ?BlockRenderer $blockRenderer = null,
        private ?TemplateEngineInterface $templateEngine = null,
        private ?GateInterface $gate = null,
    ) {}

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
        $locale = is_string($params['locale'] ?? null) ? $params['locale'] : 'en';

        $content = $this->contentRepository->findById($id);

        if ($content === null || $content->isDeleted()) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        $translation = $this->translationRepository->findByContentAndLocale($id, $locale);
        $blocks = $this->blockRepository->findByContentAndLocale($id, $locale);

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
            'previewUrl' => "/admin/cms/content/$id/preview/render?locale=$locale",
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
        $params = $request->getQueryParams();
        $locale = is_string($params['locale'] ?? null) ? $params['locale'] : 'en';

        // For POST requests (live preview), use the submitted body
        $method = $request->getMethod();
        $title = '';
        $body = '';
        $excerpt = '';
        $blocksJson = '';

        if ($method === 'POST') {
            /** @var array<string, mixed> $postData */
            $postData = (array) ($request->getParsedBody() ?? []);
            $title = trim(is_string($postData['title'] ?? null) ? $postData['title'] : '');
            $body = is_string($postData['body'] ?? null) ? $postData['body'] : '';
            $excerpt = trim(is_string($postData['excerpt'] ?? null) ? $postData['excerpt'] : '');
            $blocksJson = is_string($postData['blocks_json'] ?? null) ? $postData['blocks_json'] : '';
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

        return new Response(body: $previewHtml)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    private function buildPreviewHtml(
        string $title,
        string $body,
        string $excerpt,
        string $renderedBlocks,
        string $locale,
    ): string {
        $content = $renderedBlocks !== '' ? $renderedBlocks : $body;

        return <<<HTML
            <!DOCTYPE html>
            <html lang="{$locale}" data-theme="light" data-extension="cms">
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
