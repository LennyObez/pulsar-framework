<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Access;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a third-party data access evaluation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThirdPartyAccessResult
{
    private function __construct(
        public bool $granted,
        public string $reason,
        public string $requestingParty,
        public string $dataSubjectId,
        public string $purpose,
    ) {}

    /**
     * Create a granted result.
     */
    #[NoDiscard]
    public static function granted(string $requestingParty, string $dataSubjectId, string $purpose): self
    {
        return new self(
            granted: true,
            reason: 'Access granted per Data Act Art. 6 conditions',
            requestingParty: $requestingParty,
            dataSubjectId: $dataSubjectId,
            purpose: $purpose,
        );
    }

    /**
     * Create a denied result with a reason.
     */
    #[NoDiscard]
    public static function denied(string $reason): self
    {
        return new self(
            granted: false,
            reason: $reason,
            requestingParty: '',
            dataSubjectId: '',
            purpose: '',
        );
    }
}
