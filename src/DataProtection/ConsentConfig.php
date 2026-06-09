<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Consent tracking configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConsentConfig
{
    /**
     * @param bool $requireExplicit
     * @param list<string> $purposes
     */
    public function __construct(
        public bool $requireExplicit = true,
        public array $purposes = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            requireExplicit: Coerce::strictBool($data['require_explicit'] ?? null, true),
            purposes: Coerce::listOfString($data['purposes'] ?? null),
        );
    }
}
