<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\CmsIntegration;

use Pulsar\Api\Api;

/**
 * A plan entry in a PricingTableBlock.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PricingTablePlan
{
    /**
     * @param list<string> $features
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $priceDisplay,
        public array $features,
        public string $ctaText,
        public string $ctaUrl,
    ) {}
}
