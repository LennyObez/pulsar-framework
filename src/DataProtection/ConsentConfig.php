<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_bool;

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
     * @param array<mixed, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawExplicit = $data['require_explicit'] ?? true;
        $rawPurposes = $data['purposes'] ?? [];

        /** @var list<string> $purposes */
        $purposes = is_array($rawPurposes) ? $rawPurposes : [];

        return new self(
            requireExplicit: is_bool($rawExplicit) ? $rawExplicit : true,
            purposes: $purposes,
        );
    }
}
