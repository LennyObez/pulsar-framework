<?php

declare(strict_types=1);

namespace Pulsar\Api\Format;

use Pulsar\Api\Api;

/**
 * Common interface for all response format renderers.
 */
#[Api(since: '1.0.0')]
interface ResponseRendererInterface
{
    /**
     * Render resource data into the specific format.
     *
     * @param array<string, mixed> $data The serialized resource data
     * @param ResponseContext $context Rendering context (pagination, links, includes)
     *
     * @return array<string, mixed> The rendered response body
     */
    public function render(array $data, ResponseContext $context): array;

    /**
     * Render a collection of resource data.
     *
     * @param list<array<string, mixed>> $items The serialized resource items
     * @param ResponseContext $context Rendering context
     *
     * @return array<string, mixed> The rendered response body
     */
    public function renderCollection(array $items, ResponseContext $context): array;

    /**
     * Get the content type this renderer produces.
     */
    public function contentType(): string;
}
