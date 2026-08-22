<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Result of processing a digital download request.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DownloadResult
{
    /**
     * @param bool $success Whether the download was authorized
     * @param string|null $filePath Filesystem path to the downloadable file
     * @param string|null $fileName Original filename for the download response
     * @param int|null $downloadsRemaining Number of downloads still available after this one
     */
    public function __construct(
        public bool $success,
        public ?string $filePath,
        public ?string $fileName,
        public ?int $downloadsRemaining,
    ) {}
}
