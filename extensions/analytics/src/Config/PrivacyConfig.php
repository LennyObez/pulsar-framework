<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Config;

use Pulsar\Api\Api;

/**
 * Privacy-related analytics configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PrivacyConfig
{
    /**
     * @param bool $respectDnt When true, skip tracking for visitors with DNT header set
     * @param bool $anonymizeReferrer When true, strip query strings from referrer URLs
     * @param bool $requireConsent When true, check ConsentManagerInterface before tracking
     *
     * GDPR-DEFAULT (external audit): `requireConsent` defaults to true. The
     * extension advertises GDPR / ePrivacy compliance, and a default of
     * false silently bypassed consent collection in every fresh deployment.
     * Apps that operate outside the EU and have a legal basis other than
     * consent can opt out explicitly via `require_consent: false` in their
     * analytics config — the change is now visible in code, not the
     * default.
     */
    public function __construct(
        public bool $respectDnt = true,
        public bool $anonymizeReferrer = true,
        public bool $requireConsent = true,
    ) {}

    /**
     * @param array{
     *     respect_dnt?: bool|int|string,
     *     anonymize_referrer?: bool|int|string,
     *     require_consent?: bool|int|string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            respectDnt: (bool) ($data['respect_dnt'] ?? true),
            anonymizeReferrer: (bool) ($data['anonymize_referrer'] ?? true),
            requireConsent: (bool) ($data['require_consent'] ?? true),
        );
    }
}
