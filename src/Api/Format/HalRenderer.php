<?php

declare(strict_types=1);

namespace Pulsar\Api\Format;

use Override;
use Pulsar\Api\Api;

/**
 * HAL (Hypertext Application Language) renderer (optional, opt-in).
 *
 * Produces responses conforming to the HAL spec with _links and _embedded.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HalRenderer implements ResponseRendererInterface
{
    #[Override]
    public function render(array $data, ResponseContext $context): array
    {
        $response = $data;

        // Remove _meta if present: HAL doesn't use _meta
        unset($response['_meta']);

        $links = ['self' => ['href' => '']];
        $response['_links'] = $links;

        return $response;
    }

    #[Override]
    public function renderCollection(array $items, ResponseContext $context): array
    {
        $links = ['self' => ['href' => '']];

        if ($context->paginationLinks !== null) {
            $paginationLinksArr = $context->paginationLinks->toArray();

            if ($paginationLinksArr['first'] !== null) {
                $links['first'] = ['href' => $paginationLinksArr['first']];
            }

            if ($paginationLinksArr['last'] !== null) {
                $links['last'] = ['href' => $paginationLinksArr['last']];
            }

            if ($paginationLinksArr['next'] !== null) {
                $links['next'] = ['href' => $paginationLinksArr['next']];
            }

            if ($paginationLinksArr['prev'] !== null) {
                $links['prev'] = ['href' => $paginationLinksArr['prev']];
            }
        }

        $response = [
            '_links' => $links,
            '_embedded' => [
                $context->resourceType !== '' ? $context->resourceType : 'items' => $items,
            ],
        ];

        if ($context->paginationMeta !== null) {
            $response = [...$response, ...$context->paginationMeta->toArray()];
        }

        return $response;
    }

    #[Override]
    public function contentType(): string
    {
        return 'application/hal+json';
    }
}
