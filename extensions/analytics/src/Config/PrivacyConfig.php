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
     * @param bool $requireConsent When true, check ConsentManagerInterface before tracking
     */
    public function __construct(
        public bool $respectDnt = true,
        public bool $anonymizeReferrer = true,
        public bool $requireConsent = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            respectDnt: (bool) ($data['respect_dnt'] ?? true),
            anonymizeReferrer: (bool) ($data['anonymize_referrer'] ?? true),
            requireConsent: (bool) ($data['require_consent'] ?? false),
        );
    }
}
