<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;

use function array_reverse;

/**
 * Generates breadcrumb trails by walking up the content hierarchy.
 *
 * Builds a BreadcrumbItem array from the root ancestor down to the
 * current content item, with locale-aware URLs.
 */
#[Internal(reason: 'CMS navigation — implementation detail')]
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
        $trail = [];
        $current = $content;
        $maxDepth = $this->config->maxHierarchyDepth;
        $depth = 0;

        // Walk up the hierarchy collecting ancestors
        while ($current !== null && $depth < $maxDepth) {
            $translation = $this->translationRepository->findByContentAndLocale(
                $current->id,
                $locale,
            );

            if ($translation !== null) {
                $url = $this->buildUrl($translation->path, $locale);
                $trail[] = new BreadcrumbItem(
                    label: $translation->title,
                    url: $url,
                    isCurrent: $current->id === $content->id,
                );
            }

            if ($current->parentId === null) {
                break;
            }

            $current = $this->contentRepository->findById($current->parentId);
            $depth++;
        }

        // Trail was collected bottom-up; reverse to root-first order
        return array_reverse($trail);
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
