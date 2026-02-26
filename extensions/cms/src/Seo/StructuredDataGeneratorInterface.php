<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;

/**
 * Strategy interface for generating content-type-specific JSON-LD structured data.
 */
#[Api(since: '1.0.0')]
interface StructuredDataGeneratorInterface
{
    /**
     * Whether this generator supports the given content type.
     */
    public function supports(Content $content): bool;

    /**
     * Generate JSON-LD structured data for the given content and translation.
     *
     * @return array<string, mixed> A single JSON-LD object
     */
    public function generate(Content $content, ContentTranslation $translation, string $baseUrl): array;
}
