<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\I18n;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\I18n\Locale\LocaleUrlResolverInterface;
use Pulsar\I18n\Locale\UrlPrefixExtractor;

use function in_array;
use function str_starts_with;

/**
 * CMS-specific locale URL resolver.
 *
 * Resolves alternate-locale URLs for content by looking up translated
 * content paths via the translation repository. Falls back to simple
 * locale prefix swapping when no content context is available.
 *
 * Registered as a singleton: use {@see withContentId()} to create a
 * per-request copy with content context for translated slug resolution.
 */
#[Internal(reason: 'CMS i18n; locale URL resolution via content translations')]
final readonly class CmsLocaleUrlResolver implements LocaleUrlResolverInterface
{
    public function __construct(
        private ContentTranslationRepositoryInterface $translationRepository,
        private UrlPrefixExtractor $extractor,
        private CmsConfig $config,
        private ?string $currentContentId = null,
    ) {}

    /**
     * Return a copy with the given content ID for translated slug resolution.
     *
     * Controllers call this per-request to bind the current content context
     * without mutating the shared singleton. The clone shares the stateless
     * translation repository: safe for single-threaded PHP request handling.
     */
    public function withContentId(string $contentId): self
    {
        return clone($this, ['currentContentId' => $contentId]);
    }

    #[Override]
    public function resolveAlternates(string $currentPath, string $currentLocale): array
    {
        if ($this->currentContentId === null) {
            // No content context; fall back to prefix swapping
            $alternates = [];

            foreach ($this->config->supportedLocales as $locale) {
                $alternates[$locale] = $this->extractor->buildPath(
                    $currentPath,
                    $locale,
                    $this->config->defaultLocale,
                    $this->config->defaultLocaleInUrl,
                );
            }

            return $alternates;
        }

        $translations = $this->translationRepository->findByContentId($this->currentContentId);
        $alternates = [];

        foreach ($translations as $translation) {
            if (in_array($translation->locale, $this->config->supportedLocales, true)) {
                $contentPath = str_starts_with($translation->path, '/')
                    ? $translation->path
                    : '/' . $translation->path;

                $alternates[$translation->locale] = $this->extractor->buildPath(
                    $contentPath,
                    $translation->locale,
                    $this->config->defaultLocale,
                    $this->config->defaultLocaleInUrl,
                );
            }
        }

        return $alternates;
    }
}
