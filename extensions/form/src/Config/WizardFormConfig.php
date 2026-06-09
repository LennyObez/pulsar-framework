<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Wizard state machine configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WizardFormConfig
{
    public function __construct(
        public int $ttl,
        public string $storage,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            ttl: Coerce::int($data['ttl'] ?? null, 1800),
            storage: Coerce::string($data['storage'] ?? null, 'server'),
        );
    }
}
