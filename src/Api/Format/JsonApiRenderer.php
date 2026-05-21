<?php

declare(strict_types=1);

namespace Pulsar\Api\Format;

use Override;
use Pulsar\Api\Api;

use function array_diff_key;
use function array_flip;
use function is_int;
use function is_string;

/**
 * JSON:API 1.1 specification renderer (optional, opt-in).
 *
 * Produces responses conforming to the JSON:API spec:
 * {
 *     "data": {
 *         "type": "users",
 *         "id": "1",
 *         "attributes": { ... }
 *     },
 *     "meta": { ... },
 *     "links": { ... }
 * }
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class JsonApiRenderer implements ResponseRendererInterface
{
    /**
     * Fields that are extracted as top-level JSON:API members, not attributes.
     */
    private const array RESERVED_FIELDS = ['id', 'type'];

    #[Override]
    public function render(array $data, ResponseContext $context): array
    {
        $response = ['data' => $this->toJsonApiResource($data, $context->resourceType)];

        if ($context->meta !== []) {
            $response['meta'] = $context->meta;
        }

        return $response;
    }

    #[Override]
    public function renderCollection(array $items, ResponseContext $context): array
    {
        $resources = [];

        foreach ($items as $item) {
            $resources[] = $this->toJsonApiResource($item, $context->resourceType);
        }

        $response = ['data' => $resources];

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
        return 'application/vnd.api+json';
    }

    /**
     * Transform a flat resource array into JSON:API resource object.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function toJsonApiResource(array $data, string $resourceType): array
    {
        /** @var mixed $rawId */
        $rawId = $data['id'] ?? '';
        $id = is_string($rawId) || is_int($rawId) ? (string) $rawId : '';
        /** @var mixed $rawType */
        $rawType = $data['type'] ?? $resourceType;
        $type = is_string($rawType) ? $rawType : $resourceType;

        $attributes = array_diff_key($data, array_flip(self::RESERVED_FIELDS));

        // Remove _meta from attributes if present
        /** @var mixed $meta */
        $meta = [];

        if (isset($attributes['_meta'])) {
            /** @var mixed $meta */
            $meta = $attributes['_meta'];
            unset($attributes['_meta']);
        }

        $resource = [
            'type' => $type,
            'id' => $id,
            'attributes' => $attributes,
        ];

        if ($meta !== []) {
            $resource = [...$resource, 'meta' => $meta];
        }

        return $resource;
    }
}
