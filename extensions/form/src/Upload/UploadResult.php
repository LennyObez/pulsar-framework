<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Upload;

use Pulsar\Api\Api;

/**
 * Result of a successful file upload processing.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class UploadResult
{
    public function __construct(
        public string $storagePath,
        public string $storageName,
        public string $originalName,
        public string $mimeType,
        public int $size,
    ) {}
}
