<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\Dsar;

use Pulsar\Api\Api;

/**
 * A file attachment included in a DSAR data package.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DsarAttachment
{
    /**
     * @param string $filename Filename within the ZIP package
     * @param string $content File content (binary safe)
     * @param string $mimeType MIME type of the attachment
     */
    public function __construct(
        public string $filename,
        public string $content,
        public string $mimeType = 'application/octet-stream',
    ) {}
}
