<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Resolver;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;

/**
 * Resolves GraphQL queries for Media type.
 * @api
 */
#[Api(since: '1.0.0')]
readonly class MediaResolver
{
    public function __construct(
        private MediaRepositoryInterface $mediaRepository,
    ) {}

    /**
     * Resolve a media asset by ID.
     *
     * @return array<string, mixed>|null
     */
    public function resolveById(string $id): ?array
    {
        $asset = $this->mediaRepository->findById($id);

        if ($asset === null) {
            return null;
        }

        return [
            'id' => $asset->id,
            'tenantId' => $asset->tenantId,
            'filename' => $asset->filename,
            'mimeType' => $asset->mimeType,
            'fileSize' => $asset->fileSize,
            'width' => $asset->width,
            'height' => $asset->height,
            'altTextDefault' => $asset->altTextDefault,
            'visibility' => $asset->visibility->value,
            'createdAt' => $asset->createdAt->format('c'),
        ];
    }
}
