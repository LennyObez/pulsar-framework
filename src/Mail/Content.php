<?php

declare(strict_types=1);

namespace Pulsar\Mail;

use Pulsar\Api\Api;

/**
 * Defines the body content (HTML and/or plain text) for a mailable.
 */
#[Api(since: '1.0.0')]
final readonly class Content
{
    public function __construct(
        public ?string $html = null,
        public ?string $text = null,
    ) {}
}
