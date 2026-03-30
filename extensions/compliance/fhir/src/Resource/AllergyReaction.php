<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

use function array_map;
use function array_values;

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
     * @param array{
     *     substance?: array<string, mixed>|null,
     *     manifestation?: list<array<string, mixed>>,
     *     severity?: string|null,
     *     onset?: string|null,
     *     description?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $substanceData = $data['substance'] ?? null;

        return new self(
            substance: $substanceData !== null ? CodeableConcept::fromArray($substanceData) : null,
            manifestation: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $data['manifestation'] ?? [],
            )),
            severity: $data['severity'] ?? null,
            onset: $data['onset'] ?? null,
            description: $data['description'] ?? null,
        );
    }
}
