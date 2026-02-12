<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\Content;

/**
 * Generates breadcrumb trails for content items based on their hierarchy.
 */
#[Api(since: '1.0.0')]
interface BreadcrumbGeneratorInterface
{
    /**
     * Generate breadcrumb items for a content item in the given locale.
     *
     * @return list<BreadcrumbItem>
     */
    public function generate(Content $content, string $locale): array;
}
