<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_string;

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
        $rawTtl = $data['ttl'] ?? 1800;

        return new self(
            ttl: is_int($rawTtl) ? $rawTtl : 1800,
            storage: is_string($data['storage'] ?? null) ? $data['storage'] : 'server',
        );
    }
}
