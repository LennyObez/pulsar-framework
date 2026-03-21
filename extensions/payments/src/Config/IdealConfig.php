<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * iDEAL payment gateway configuration.
 *
 * iDEAL is the most popular online payment method in the Netherlands.
 * Integrated via Stripe Payment Methods API or Mollie.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IdealConfig
{
    public function __construct(
        public bool $enabled,
        public string $provider,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $enabled = (bool) ($data['enabled'] ?? false);
        $providerVal = $data['provider'] ?? null;
        $provider = is_string($providerVal) ? $providerVal : 'stripe';

        return new self(
            enabled: $enabled,
            provider: $provider,
        );
    }
}
