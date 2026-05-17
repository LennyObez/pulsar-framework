<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

use function is_string;

/**
 * A technical identifier for a resource, distinct from the resource's FHIR ID.
 *
 * @see https://www.hl7.org/fhir/datatypes.html#Identifier
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Identifier
{
    public function __construct(
        public ?string $use = null,
        public ?CodeableConcept $type = null,
        public ?string $system = null,
        public ?string $value = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->use !== null) {
            $data['use'] = $this->use;
        }

        if ($this->type !== null) {
            $data['type'] = $this->type->toArray();
        }

        if ($this->system !== null) {
            $data['system'] = $this->system;
        }

        if ($this->value !== null) {
            $data['value'] = $this->value;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $typeData */
        $typeData = $data['type'] ?? null;

        return new self(
            use: is_string($data['use'] ?? null) ? $data['use'] : null,
            type: $typeData !== null ? CodeableConcept::fromArray($typeData) : null,
            system: is_string($data['system'] ?? null) ? $data['system'] : null,
            value: is_string($data['value'] ?? null) ? $data['value'] : null,
        );
    }
}
