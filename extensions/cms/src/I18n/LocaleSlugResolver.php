<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\I18n;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;

/**
 * Resolves content by locale prefix and path from a request URL.
 *
 * Coordinates locale resolution, redirect lookup, and translation
 * resolution into a single pipeline. Used by the public ContentController
 * and the CmsSlugRedirectMiddleware to resolve URLs like:
 *
 *   /{locale}/{path}  -> locale-prefixed content
 *   /{path}           -> default locale content (when defaultLocaleInUrl=false)
 *
 * When a translation is not found in the requested locale, optionally
 * falls back to the default locale translation (configurable).
 */
#[Internal(reason: 'CMS i18n slug resolution — implementation detail')]
final readonly class LocaleSlugResolver
{
    public function __construct(
        private LocaleResolver $localeResolver,
        private ContentTranslationRepositoryInterface $translationRepository,
        private RedirectRepositoryInterface $redirectRepository,
    ) {}

    /**
     * Resolve a request to a locale + content translation.
     *
     * Returns a resolved result containing the locale, content path,
     * and matched translation (or redirect), or null if nothing matches.
     */
    public function resolve(
        ServerRequestInterface $request,
        CmsConfig $config,
        bool $fallbackToDefault = false,
    ): ?LocaleSlugResult {
        $locale = $this->localeResolver->resolve($request, $config);
        $contentPath = $this->localeResolver->stripLocalePrefix(
            $request->getUri()->getPath(),
            $locale,
            $config,
        );

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        // Check redirects first
        $redirect = $this->redirectRepository->findByPath($contentPath, $locale, $tenantId);

        if ($redirect !== null) {
            return new LocaleSlugResult(
                locale: $locale,
                contentPath: $contentPath,
                translation: null,
                redirect: $redirect,
            );
        }

        // Resolve translation by path
        $translation = $this->translationRepository->findByPath($locale, $contentPath, $tenantId);

        if ($translation !== null) {
            return new LocaleSlugResult(
                locale: $locale,
                contentPath: $contentPath,
                translation: $translation,
                redirect: null,
            );
        }

        // Fallback to default locale if configured and requested locale differs
        if ($fallbackToDefault && $locale !== $config->defaultLocale) {
            $defaultTranslation = $this->translationRepository->findByPath(
                $config->defaultLocale,
                $contentPath,
                $tenantId,
            );

            if ($defaultTranslation !== null) {
                return new LocaleSlugResult(
                    locale: $config->defaultLocale,
                    contentPath: $contentPath,
                    translation: $defaultTranslation,
                    redirect: null,
                    isFallback: true,
                );
            }
        }

        return null;
    }

    /**
     * Resolve content by explicit locale and path (no request needed).
     *
     * Used for programmatic lookups (e.g., sitemap generation, link validation).
     */
    public function resolveByPath(
        string $locale,
        string $path,
        CmsConfig $config,
        ?string $tenantId = null,
        bool $fallbackToDefault = false,
    ): ?LocaleSlugResult {
        $translation = $this->translationRepository->findByPath($locale, $path, $tenantId);

        if ($translation !== null) {
            return new LocaleSlugResult(
                locale: $locale,
                contentPath: $path,
                translation: $translation,
                redirect: null,
            );
        }

        if ($fallbackToDefault && $locale !== $config->defaultLocale) {
            $defaultTranslation = $this->translationRepository->findByPath(
                $config->defaultLocale,
                $path,
                $tenantId,
            );

            if ($defaultTranslation !== null) {
                return new LocaleSlugResult(
                    locale: $config->defaultLocale,
                    contentPath: $path,
                    translation: $defaultTranslation,
                    redirect: null,
                    isFallback: true,
                );
            }
        }

        return null;
    }

    /**
     * Get all available locales for a content item.
     *
     * Returns an array of locale codes that have translations,
     * useful for building locale switchers and admin locale tabs.
     *
     * @return list<string>
     */
    public function availableLocales(string $contentId): array
    {
        $translations = $this->translationRepository->findByContentId($contentId);
        $locales = [];

        foreach ($translations as $translation) {
            $locales[] = $translation->locale;
        }

        return $locales;
    }
}
