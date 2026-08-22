<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;

use function array_map;
use function array_merge;
use function array_reverse;

/**
 * Generates breadcrumb trails by walking up the content hierarchy.
 *
 * Uses a single recursive CTE query to load the ancestor chain and a
 * single batch query for translations, replacing the previous N+1
 * pattern of one query per ancestor level.
 */
#[Internal(reason: 'CMS navigation; implementation detail')]
/**
 * @psalm-api Bound to BreadcrumbGeneratorInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
final readonly class BreadcrumbGenerator implements BreadcrumbGeneratorInterface
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private CmsConfig $config,
    ) {}

    /**
     * @return list<BreadcrumbItem>
     */
    #[Override]
    public function generate(Content $content, string $locale): array
    {
        // Load the full ancestor chain in a single recursive CTE query
        $ancestors = $this->contentRepository->findAncestors(
            $content->id,
            $this->config->maxHierarchyDepth,
        );

        // Batch-load translations for the current item + all ancestors
        $allContentIds = array_merge(
            [$content->id],
            array_map(static fn(Content $c): string => $c->id, $ancestors),
        );
        $translationsByContentId = $this->translationRepository->findByContentIds($allContentIds);

        // Build trail bottom-up: current item first, then ancestors
        $trail = [];

        $currentTranslation = $this->pickTranslation($translationsByContentId, $content->id, $locale);

        if ($currentTranslation !== null) {
            $trail[] = new BreadcrumbItem(
                label: $currentTranslation->title,
                url: $this->buildUrl($currentTranslation->path, $locale),
                isCurrent: true,
            );
        }

        foreach ($ancestors as $ancestor) {
            $translation = $this->pickTranslation($translationsByContentId, $ancestor->id, $locale);

            if ($translation !== null) {
                $trail[] = new BreadcrumbItem(
                    label: $translation->title,
                    url: $this->buildUrl($translation->path, $locale),
                    isCurrent: false,
                );
            }
        }

        // Trail was collected bottom-up; reverse to root-first order
        return array_reverse($trail);
    }

    /**
     * Pick the translation matching the requested locale from the batch result.
     *
     * @param array<string, list<ContentTranslation>> $translationsByContentId
     */
    private function pickTranslation(array $translationsByContentId, string $contentId, string $locale): ?ContentTranslation
    {
        $translations = $translationsByContentId[$contentId] ?? [];

        /** @var ContentTranslation|null $found */
        $found = array_find($translations, static fn(ContentTranslation $t): bool => $t->locale === $locale);

        return $found;
    }

    private function buildUrl(string $path, string $locale): string
    {
        $isDefaultLocale = $locale === $this->config->defaultLocale;

        if ($isDefaultLocale && !$this->config->defaultLocaleInUrl) {
            return '/' . $path;
        }

        return '/' . $locale . '/' . $path;
    }
}
