<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Config;

use Pulsar\Api\Api;

/**
 * Privacy-related analytics configuration.
 */
#[Api(since: '1.0.0')]
final readonly class PrivacyConfig
{
    /**
     * @param bool $respectDnt When true, skip tracking for visitors with DNT header set
     * @param bool $anonymizeReferrer When true, strip query strings from referrer URLs
     */
    public function __construct(
        public bool $respectDnt = false,
        public bool $anonymizeReferrer = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            respectDnt: (bool) ($data['respect_dnt'] ?? false),
            anonymizeReferrer: (bool) ($data['anonymize_referrer'] ?? false),
        );
    }
}
