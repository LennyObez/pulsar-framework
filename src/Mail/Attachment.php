<?php

declare(strict_types=1);

namespace Pulsar\Mail;

use Pulsar\Api\Api;

/**
 * A file attachment for an email message.
 */
#[Api(since: '1.0.0')]
final readonly class Attachment
{
    public function __construct(
        public string $filename,
        public string $content,
        public string $mimeType,
        public bool $inline = false,
        public ?string $cid = null,
    ) {}
}
