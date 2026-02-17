<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Editor;

use Pulsar\Api\Api;

/**
 * The result of processing rich text input.
 */
#[Api(since: '1.0.0')]
final readonly class RichTextResult
{
    public function __construct(
        public string $html,
        public string $plainText,
        public string $preview,
        public EditorFormat $sourceFormat,
    ) {}
}
