<?php

declare(strict_types=1);

namespace Pulsar\Notification\Consent;

use Pulsar\Api\Api;

/**
 * Immutable record of a consent action.
 *
 * IP addresses are stored as hashes — never raw values.
 */
#[Api(since: '1.0.0')]
readonly class ConsentRecord
{
    public function __construct(
        public int $timestamp,
        public ConsentType $consentType,
        public string $channel,
        public LegalBasis $legalBasis,
        public ConsentSource $source,
        public ?string $ipAddressHash = null,
    ) {}
}
