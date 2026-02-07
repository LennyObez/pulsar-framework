<?php

declare(strict_types=1);

namespace Pulsar\Mail\Security;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Emitted when PHI is detected in a mail field during HIPAA-mode scrubbing.
 */
#[Api(since: '1.0.0')]
final readonly class PhiDetectedEvent
{
    public function __construct(
        public string $messageId,
        public string $field,
        public string $pattern,
        public int $detectedAt,
    ) {}

    #[NoDiscard]
    public static function create(string $messageId, string $field, string $pattern): self
    {
        return new self(
            messageId: $messageId,
            field: $field,
            pattern: $pattern,
            detectedAt: time(),
        );
    }
}
