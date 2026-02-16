<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

use function is_array;
use function is_float;
use function is_int;
use function is_string;

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
        $rawCodes = is_array($data['countryCodes'] ?? null) ? $data['countryCodes'] : [];
        $countryCodes = [];
        foreach ($rawCodes as $v) {
            $countryCodes[] = is_string($v) ? $v : '';
        }

        return new self(
            category: is_string($data['category'] ?? null) ? $data['category'] : '',
            rate: is_float($data['rate'] ?? null) || is_int($data['rate'] ?? null) ? (float) $data['rate'] : 0.0,
            label: is_string($data['label'] ?? null) ? $data['label'] : '',
            countryCodes: $countryCodes,
        );
    }
}
