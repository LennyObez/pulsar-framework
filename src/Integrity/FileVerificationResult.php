<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Api;

/**
 * Result of verifying a single file against its manifest entry.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FileVerificationResult
{
    public function __construct(
        public string $path,
        public FileVerificationStatus $status,
        public ?string $expectedHash = null,
        public ?string $actualHash = null,
    ) {}
}
