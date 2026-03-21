<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

use function is_string;

/**
 * A measured amount (or an amount that can potentially be measured).
 *
 * @see https://www.hl7.org/fhir/datatypes.html#Quantity
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Quantity
{
    public function __construct(
        public ?float $value = null,
        public ?string $comparator = null,
        public ?string $unit = null,
        public ?string $system = null,
        public ?string $code = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->value !== null) {
            $data['value'] = $this->value;
        }

        if ($this->comparator !== null) {
            $data['comparator'] = $this->comparator;
        }

        if ($this->unit !== null) {
            $data['unit'] = $this->unit;
        }

        if ($this->system !== null) {
            $data['system'] = $this->system;
        }

        if ($this->code !== null) {
            $data['code'] = $this->code;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            value: isset($data['value']) && is_numeric($data['value']) ? (float) $data['value'] : null,
            comparator: is_string($data['comparator'] ?? null) ? $data['comparator'] : null,
            unit: is_string($data['unit'] ?? null) ? $data['unit'] : null,
            system: is_string($data['system'] ?? null) ? $data['system'] : null,
            code: is_string($data['code'] ?? null) ? $data['code'] : null,
        );
    }
}
