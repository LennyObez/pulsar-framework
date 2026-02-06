<?php

declare(strict_types=1);

namespace Pulsar\Storage;

use Pulsar\Api\Api;

/**
 * Metadata for storage objects.
 */
#[Api]
readonly class StorageMetadata
{
    /**
     * @param array<string, string> $customHeaders Additional headers/metadata
     */
    public function __construct(
        public ?string $contentType = null,
        public ?string $cacheControl = null,
        public array $customHeaders = [],
    ) {}
}
