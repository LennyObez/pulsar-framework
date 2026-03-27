<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * Klarna payment gateway configuration.
 *
 * Supports Klarna Pay Later, Pay Now, and Slice It (installments).
 * Popular across Nordics and DACH regions. Integrated via Stripe.
 * @api
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
     * @param array{
     *     enabled?: bool|int|string,
     *     region?: string,
     *     pay_later_enabled?: bool|int|string,
     *     pay_now_enabled?: bool|int|string,
     *     slice_it_enabled?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $regionVal = $data['region'] ?? 'eu';
        $region = in_array($regionVal, self::VALID_REGIONS, true) ? $regionVal : 'eu';

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            region: $region,
            payLaterEnabled: (bool) ($data['pay_later_enabled'] ?? true),
            payNowEnabled: (bool) ($data['pay_now_enabled'] ?? true),
            sliceItEnabled: (bool) ($data['slice_it_enabled'] ?? true),
        );
    }
}
