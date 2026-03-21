<?php

declare(strict_types=1);

namespace Pulsar\Storage;

use Pulsar\Api\Api;

/**
 * Represents an object in storage.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StorageObject
{
    public function __construct(
        public string $key,
        public int $size,
        public int $lastModified,
        public ?string $contentType = null,
    ) {}
}
