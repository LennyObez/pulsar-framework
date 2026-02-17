<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function is_string;

/**
 * Measurements and simple assertions made about a patient or other subject.
 *
 * @see https://www.hl7.org/fhir/observation.html
 */
#[Api(since: '1.0.0')]
final readonly class Observation extends FhirResource
{
    /**
     * @param list<Identifier>     $identifier Business identifiers
     * @param list<CodeableConcept> $category  Classification of type of observation
     * @param list<Reference>      $performer  Who performed the observation
     * @param list<Reference>      $basedOn    Fulfills plan, proposal, or order
     */
    public function __construct(
        ?string $id = null,
        ?Meta $meta = null,
        ?string $language = null,
        public array $identifier = [],
        public ?string $status = null,
        public array $category = [],
        public ?CodeableConcept $code = null,
        public ?Reference $subject = null,
        public ?Reference $encounter = null,
        public ?string $effectiveDateTime = null,
        public ?string $issued = null,
        public array $performer = [],
        public ?Quantity $valueQuantity = null,
        public ?CodeableConcept $valueCodeableConcept = null,
        public ?string $valueString = null,
        public ?CodeableConcept $dataAbsentReason = null,
        public array $basedOn = [],
    ) {
        parent::__construct(ResourceType::Observation, $id, $meta, $language);
    }

    #[Override]
    public function toArray(): array
    {
        $data = $this->baseToArray();

        if ($this->identifier !== []) {
            $data['identifier'] = array_map(
                static fn(Identifier $i): array => $i->toArray(),
                $this->identifier,
            );
        }

        if ($this->status !== null) {
            $data['status'] = $this->status;
        }

        if ($this->category !== []) {
            $data['category'] = array_map(
                static fn(CodeableConcept $c): array => $c->toArray(),
                $this->category,
            );
        }

        if ($this->code !== null) {
            $data['code'] = $this->code->toArray();
        }

        if ($this->subject !== null) {
            $data['subject'] = $this->subject->toArray();
        }

        if ($this->encounter !== null) {
            $data['encounter'] = $this->encounter->toArray();
        }

        if ($this->effectiveDateTime !== null) {
            $data['effectiveDateTime'] = $this->effectiveDateTime;
        }

        if ($this->issued !== null) {
            $data['issued'] = $this->issued;
        }

        if ($this->performer !== []) {
            $data['performer'] = array_map(
                static fn(Reference $r): array => $r->toArray(),
                $this->performer,
            );
        }

        if ($this->valueQuantity !== null) {
            $data['valueQuantity'] = $this->valueQuantity->toArray();
        }

        if ($this->valueCodeableConcept !== null) {
            $data['valueCodeableConcept'] = $this->valueCodeableConcept->toArray();
        }

        if ($this->valueString !== null) {
            $data['valueString'] = $this->valueString;
        }

        if ($this->dataAbsentReason !== null) {
            $data['dataAbsentReason'] = $this->dataAbsentReason->toArray();
        }

        if ($this->basedOn !== []) {
            $data['basedOn'] = array_map(
                static fn(Reference $r): array => $r->toArray(),
                $this->basedOn,
            );
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $metaData */
        $metaData = $data['meta'] ?? null;
        /** @var list<array<string, mixed>> $identifierList */
        $identifierList = $data['identifier'] ?? [];
        /** @var list<array<string, mixed>> $categoryList */
        $categoryList = $data['category'] ?? [];
        /** @var array<string, mixed>|null $codeData */
        $codeData = $data['code'] ?? null;
        /** @var array<string, mixed>|null $subjectData */
        $subjectData = $data['subject'] ?? null;
        /** @var array<string, mixed>|null $encounterData */
        $encounterData = $data['encounter'] ?? null;
        /** @var list<array<string, mixed>> $performerList */
        $performerList = $data['performer'] ?? [];
        /** @var array<string, mixed>|null $valueQuantityData */
        $valueQuantityData = $data['valueQuantity'] ?? null;
        /** @var array<string, mixed>|null $valueConceptData */
        $valueConceptData = $data['valueCodeableConcept'] ?? null;
        /** @var array<string, mixed>|null $absentReasonData */
        $absentReasonData = $data['dataAbsentReason'] ?? null;
        /** @var list<array<string, mixed>> $basedOnList */
        $basedOnList = $data['basedOn'] ?? [];

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: is_string($data['language'] ?? null) ? $data['language'] : null,
            identifier: array_values(array_map(
                static fn(array $i): Identifier => Identifier::fromArray($i),
                $identifierList,
            )),
            status: is_string($data['status'] ?? null) ? $data['status'] : null,
            category: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $categoryList,
            )),
            code: $codeData !== null ? CodeableConcept::fromArray($codeData) : null,
            subject: $subjectData !== null ? Reference::fromArray($subjectData) : null,
            encounter: $encounterData !== null ? Reference::fromArray($encounterData) : null,
            effectiveDateTime: is_string($data['effectiveDateTime'] ?? null) ? $data['effectiveDateTime'] : null,
            issued: is_string($data['issued'] ?? null) ? $data['issued'] : null,
            performer: array_values(array_map(
                static fn(array $r): Reference => Reference::fromArray($r),
                $performerList,
            )),
            valueQuantity: $valueQuantityData !== null ? Quantity::fromArray($valueQuantityData) : null,
            valueCodeableConcept: $valueConceptData !== null
                ? CodeableConcept::fromArray($valueConceptData)
                : null,
            valueString: is_string($data['valueString'] ?? null) ? $data['valueString'] : null,
            dataAbsentReason: $absentReasonData !== null
                ? CodeableConcept::fromArray($absentReasonData)
                : null,
            basedOn: array_values(array_map(
                static fn(array $r): Reference => Reference::fromArray($r),
                $basedOnList,
            )),
        );
    }
}
