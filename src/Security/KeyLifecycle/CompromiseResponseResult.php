<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a key compromise response operation.
 */
#[Api(since: '1.0.0')]
final readonly class CompromiseResponseResult
{
    public function __construct(
        public bool $success,
        public string $compromisedKid,
        public ?string $newKid,
        public ?string $incidentId,
        public ?KeyType $keyType,
        public string $failureReason,
    ) {}

    #[NoDiscard]
    public static function success(
        string $compromisedKid,
        string $newKid,
        string $incidentId,
        KeyType $keyType,
    ): self {
        return new self(
            success: true,
            compromisedKid: $compromisedKid,
            newKid: $newKid,
            incidentId: $incidentId,
            keyType: $keyType,
            failureReason: '',
        );
    }

    #[NoDiscard]
    public static function failed(string $compromisedKid, string $reason): self
    {
        return new self(
            success: false,
            compromisedKid: $compromisedKid,
            newKid: null,
            incidentId: null,
            keyType: null,
            failureReason: $reason,
        );
    }
}
