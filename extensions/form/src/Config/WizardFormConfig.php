<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     ttl?: int,
     *     storage?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            ttl: $data['ttl'] ?? 1800,
            storage: $data['storage'] ?? 'server',
        );
    }
}
