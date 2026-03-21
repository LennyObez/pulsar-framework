<?php

declare(strict_types=1);

namespace Pulsar\I18n;

use Pulsar\Api\Api;

/**
 * Readonly DTO for a single catalog entry.
 *
 * Carries the translated message along with metadata used
 * by the linter and template engines.
 */
#[Api(since: '1.0.0')]
final readonly class TranslationEntry
{
    public function __construct(
        public string $key,
        public string $message,
        public bool $htmlSafe = false,
        public ?string $context = null,
        public ?int $maxLength = null,
    ) {}
}
