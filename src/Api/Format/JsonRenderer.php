<?php

declare(strict_types=1);

namespace Pulsar\Api\Format;

use Override;
use Pulsar\Api\Api;

/**
 * Core JSON renderer producing a standard envelope structure.
 *
 * Response format:
 * {
 *     "data": { ... },
 *     "meta": { ... },
 *     "links": { ... }
 * }
 */
#[Api(since: '1.0.0')]
final readonly class JsonRenderer implements ResponseRendererInterface
{
    #[Override]
    public function render(array $data, ResponseContext $context): array
    {
        $response = ['data' => $data];

        if ($context->meta !== []) {
            $response['meta'] = $context->meta;
        }

        return $response;
    }

    #[Override]
    public function renderCollection(array $items, ResponseContext $context): array
    {
        $response = ['data' => $items];

        $meta = $context->meta;

        if ($context->paginationMeta !== null) {
            $meta = [...$meta, ...$context->paginationMeta->toArray()];
        }

        if ($meta !== []) {
            $response['meta'] = $meta;
        }

        if ($context->paginationLinks !== null) {
            $response['links'] = $context->paginationLinks->toArray();
        }

        return $response;
    }

    #[Override]
    public function contentType(): string
    {
        return 'application/json';
    }
}
