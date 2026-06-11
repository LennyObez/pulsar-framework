<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
     * @param array{
     *     enabled?: bool|int|string,
     *     provider?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            provider: Coerce::string($data['provider'] ?? null, 'stripe'),
        );
    }
}
