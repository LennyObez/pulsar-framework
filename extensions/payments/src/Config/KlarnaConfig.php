<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;
use function is_string;

/**
 * Klarna payment gateway configuration.
 *
 * Supports Klarna Pay Later, Pay Now, and Slice It (installments).
 * Popular across Nordics and DACH regions. Integrated via Stripe.
 */
#[Api(since: '1.0.0')]
final readonly class KlarnaConfig
{
    private const array VALID_REGIONS = ['eu', 'na', 'oc'];

    public function __construct(
        public bool $enabled,
        public string $region,
        public bool $payLaterEnabled,
        public bool $payNowEnabled,
        public bool $sliceItEnabled,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $enabled = (bool) ($data['enabled'] ?? false);
        $regionVal = $data['region'] ?? null;
        $region = is_string($regionVal) && in_array($regionVal, self::VALID_REGIONS, true)
            ? $regionVal
            : 'eu';
        $payLaterEnabled = (bool) ($data['pay_later_enabled'] ?? true);
        $payNowEnabled = (bool) ($data['pay_now_enabled'] ?? true);
        $sliceItEnabled = (bool) ($data['slice_it_enabled'] ?? true);

        return new self(
            enabled: $enabled,
            region: $region,
            payLaterEnabled: $payLaterEnabled,
            payNowEnabled: $payNowEnabled,
            sliceItEnabled: $sliceItEnabled,
        );
    }
}
