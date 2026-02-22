<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * A single tax rate rule mapping categories and countries to a rate.
 */
#[Api(since: '1.0.0')]
final readonly class TaxRateConfig
{
    /**
     * @param string $category Tax category identifier (e.g. "standard", "reduced", "digital")
     * @param float $rate Tax rate as a decimal (e.g. 0.21 for 21%)
     * @param string $label Human-readable label for display
     * @param list<string> $countryCodes ISO 3166-1 alpha-2 country codes this rate applies to
     */
    public function __construct(
        public string $category,
        public float $rate,
        public string $label,
        public array $countryCodes,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            category: (string) ($data['category'] ?? ''),
            rate: (float) ($data['rate'] ?? 0.0),
            label: (string) ($data['label'] ?? ''),
            countryCodes: array_map(strval(...), $data['countryCodes'] ?? []),
        );
    }
}
