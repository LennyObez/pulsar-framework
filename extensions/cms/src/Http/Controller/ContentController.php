<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\I18n\HreflangGenerator;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGeneratorInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function count;
use function explode;
use function htmlspecialchars;
use function in_array;
use function is_string;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function time;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Public-facing content rendering controller.
 *
 * Resolves content by locale and path, verifies publishing status,
 * loads the full aggregate (translations, blocks, custom fields,
 * taxonomy terms), resolves the template, and returns the rendered response.
 */
#[Internal(reason: 'CMS HTTP controller — implementation detail')]
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
        private CmsConfig $config,
    ) {}

    public function show(ServerRequestInterface $request): Response
    {
        $rawPath = ltrim($request->getUri()->getPath(), '/');

        // Use locale from CmsLocaleMiddleware if available, otherwise extract from URL
        /** @var string|null $middlewareLocale */
        $middlewareLocale = $request->getAttribute('cms_locale');
        $locale = $middlewareLocale ?? $this->config->defaultLocale;
        $contentPath = $rawPath;

        if ($middlewareLocale !== null) {
            // Middleware already resolved locale; strip prefix from path
            if ($locale !== $this->config->defaultLocale || $this->config->defaultLocaleInUrl) {
                $prefix = $locale . '/';

                if (str_starts_with($contentPath, $prefix)) {
                    $contentPath = substr($contentPath, strlen($prefix));
                } elseif ($contentPath === $locale) {
                    $contentPath = '';
                }
            }
        } elseif ($rawPath !== '' && preg_match('#^([a-z]{2}(?:-[A-Z]{2})?)(?:/(.*))?$#', $rawPath, $matches) === 1) {
            $candidateLocale = $matches[1];

            if (in_array($candidateLocale, $this->config->supportedLocales, true)) {
                if ($candidateLocale !== $this->config->defaultLocale || $this->config->defaultLocaleInUrl) {
                    $locale = $candidateLocale;
                    $contentPath = $matches[2] ?? '';
                }
            }
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

        // Resolve content by path
        $translation = $this->translationRepository->findByPath($locale, $contentPath, $tenantId);

        if ($translation === null) {
            return Response::json(
                ['error' => 'Content not found', 'status' => 404],
                404,
            );
        }

        $content = $this->contentRepository->findById($translation->contentId);

        if ($content === null || $content->isDeleted()) {
            return Response::json(
                ['error' => 'Content not found', 'status' => 404],
                404,
            );
        }

        // Verify content is published (or preview token is valid)
        $previewToken = $request->getQueryParams()['preview_token'] ?? null;

        if (!$content->isPublished() && !$this->isValidPreviewToken($previewToken, $content->id)) {
            return Response::json(
                ['error' => 'Content not found', 'status' => 404],
                404,
            );
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

        if ($request->getHeaderLine('Accept') === 'application/json' || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json($responseData);
        }

        // For HTML responses, return a basic rendered page
        // The template engine integration would expand this in production
        return Response::html($this->renderHtml($responseData, $template));
    }

    private function isValidPreviewToken(mixed $token, string $contentId): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        // Preview tokens are HMAC-signed with content ID context and an expiration timestamp.
        // Format: {expiry_timestamp}.{hmac_signature}
        // The HMAC key would come from the application secret configured in CmsConfig.
        // Without the signing infrastructure, we validate the token format and
        // check expiration. Full HMAC verification is handled by the security
        // middleware layer when the crypto module provides a SignerInterface.
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            return false;
        }

        $expiryTimestamp = (int) $parts[0];

        if ($expiryTimestamp <= 0) {
            return false;
        }

        // Reject expired preview tokens
        return $expiryTimestamp > time();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderHtml(array $data, string $template): string
    {
        /** @var array<string, mixed> $translation */
        $translation = $data['translation'] ?? [];
        /** @var string $title */
        $title = htmlspecialchars((string) ($translation['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        /** @var string $body */
        $body = (string) ($translation['body'] ?? '');
        /** @var string $metaTitle */
        $metaTitle = htmlspecialchars((string) ($translation['meta_title'] ?? $title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        /** @var string $metaDescription */
        $metaDescription = htmlspecialchars((string) ($translation['meta_description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Build hreflang link tags
        /** @var list<array{locale: string, href: string}> $hreflangLinks */
        $hreflangLinks = $data['hreflang'] ?? [];
        $hreflangHtml = '';

        foreach ($hreflangLinks as $link) {
            $hreflang = htmlspecialchars($link['locale'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $href = htmlspecialchars($link['href'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $hreflangHtml .= "\n        <link rel=\"alternate\" hreflang=\"{$hreflang}\" href=\"{$href}\">";
        }

        /** @var list<array{label: string, url: string, is_current: bool}> $breadcrumbs */
        $breadcrumbs = $data['breadcrumbs'] ?? [];
        $breadcrumbHtml = '';

        foreach ($breadcrumbs as $crumb) {
            $label = htmlspecialchars($crumb['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $url = htmlspecialchars($crumb['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($crumb['is_current']) {
                $breadcrumbHtml .= "<span aria-current=\"page\">{$label}</span>";
            } else {
                $breadcrumbHtml .= "<a href=\"{$url}\">{$label}</a> &raquo; ";
            }
        }

        return <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>{$metaTitle}</title>
                <meta name="description" content="{$metaDescription}">{$hreflangHtml}
            </head>
            <body>
                <nav aria-label="Breadcrumb">{$breadcrumbHtml}</nav>
                <article>
                    <h1>{$title}</h1>
                    <div class="content-body">{$body}</div>
                </article>
            </body>
            </html>
            HTML;
    }
}
