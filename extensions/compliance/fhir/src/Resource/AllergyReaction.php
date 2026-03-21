<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

use function is_string;

/**
 * Details about each adverse reaction event linked to exposure to an allergen.
 *
 * @see https://www.hl7.org/fhir/allergyintolerance-definitions.html#AllergyIntolerance.reaction
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AllergyReaction
{
    /**
     * @param list<CodeableConcept> $manifestation Clinical symptoms/signs
     */
    public function __construct(
        public ?CodeableConcept $substance = null,
        public array $manifestation = [],
        public ?string $severity = null,
        public ?string $onset = null,
        public ?string $description = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->substance !== null) {
            $data['substance'] = $this->substance->toArray();
        }

        if ($this->manifestation !== []) {
            $data['manifestation'] = array_map(
                static fn(CodeableConcept $c): array => $c->toArray(),
                $this->manifestation,
            );
        }

        if ($this->severity !== null) {
            $data['severity'] = $this->severity;
        }

        if ($this->onset !== null) {
            $data['onset'] = $this->onset;
        }

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $substanceData */
        $substanceData = $data['substance'] ?? null;
        /** @var list<array<string, mixed>> $manifestationList */
        $manifestationList = $data['manifestation'] ?? [];

        return new self(
            substance: $substanceData !== null ? CodeableConcept::fromArray($substanceData) : null,
            manifestation: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $manifestationList,
            )),
            severity: is_string($data['severity'] ?? null) ? $data['severity'] : null,
            onset: is_string($data['onset'] ?? null) ? $data['onset'] : null,
            description: is_string($data['description'] ?? null) ? $data['description'] : null,
        );
    }
}
