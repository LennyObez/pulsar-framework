<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function array_map;
use function array_values;

/**
 * Risk of harmful or undesirable physiological response related to a substance.
 *
 * @see https://www.hl7.org/fhir/allergyintolerance.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AllergyIntolerance extends FhirResource
{
    /**
     * @param list<Identifier>     $identifier Business identifiers
     * @param list<CodeableConcept> $category   Category of the substance (food | medication | environment | biologic)
     * @param list<AllergyReaction> $reaction   Adverse reactions events linked to exposure
     */
    public function __construct(
        ?string $id = null,
        ?Meta $meta = null,
        ?string $language = null,
        public array $identifier = [],
        public ?CodeableConcept $clinicalStatus = null,
        public ?CodeableConcept $verificationStatus = null,
        public ?string $type = null,
        public array $category = [],
        public ?string $criticality = null,
        public ?CodeableConcept $code = null,
        public ?Reference $patient = null,
        public ?Reference $encounter = null,
        public ?string $onsetDateTime = null,
        public ?string $recordedDate = null,
        public ?Reference $recorder = null,
        public ?Reference $asserter = null,
        public array $reaction = [],
    ) {
        parent::__construct(ResourceType::AllergyIntolerance, $id, $meta, $language);
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

        if ($this->clinicalStatus !== null) {
            $data['clinicalStatus'] = $this->clinicalStatus->toArray();
        }

        if ($this->verificationStatus !== null) {
            $data['verificationStatus'] = $this->verificationStatus->toArray();
        }

        if ($this->type !== null) {
            $data['type'] = $this->type;
        }

        if ($this->category !== []) {
            $data['category'] = array_map(
                static fn(CodeableConcept $c): array => $c->toArray(),
                $this->category,
            );
        }

        if ($this->criticality !== null) {
            $data['criticality'] = $this->criticality;
        }

        if ($this->code !== null) {
            $data['code'] = $this->code->toArray();
        }

        if ($this->patient !== null) {
            $data['patient'] = $this->patient->toArray();
        }

        if ($this->encounter !== null) {
            $data['encounter'] = $this->encounter->toArray();
        }

        if ($this->onsetDateTime !== null) {
            $data['onsetDateTime'] = $this->onsetDateTime;
        }

        if ($this->recordedDate !== null) {
            $data['recordedDate'] = $this->recordedDate;
        }

        if ($this->recorder !== null) {
            $data['recorder'] = $this->recorder->toArray();
        }

        if ($this->asserter !== null) {
            $data['asserter'] = $this->asserter->toArray();
        }

        if ($this->reaction !== []) {
            $data['reaction'] = array_map(
                static fn(AllergyReaction $r): array => $r->toArray(),
                $this->reaction,
            );
        }

        return $data;
    }

    /**
     * @param array{
     *     id?: string|null,
     *     meta?: array<string, mixed>|null,
     *     language?: string|null,
     *     identifier?: list<array<string, mixed>>,
     *     clinicalStatus?: array<string, mixed>|null,
     *     verificationStatus?: array<string, mixed>|null,
     *     type?: string|null,
     *     category?: list<array<string, mixed>>,
     *     criticality?: string|null,
     *     code?: array<string, mixed>|null,
     *     patient?: array<string, mixed>|null,
     *     encounter?: array<string, mixed>|null,
     *     onsetDateTime?: string|null,
     *     recordedDate?: string|null,
     *     recorder?: array<string, mixed>|null,
     *     asserter?: array<string, mixed>|null,
     *     reaction?: list<array<string, mixed>>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $metaData = $data['meta'] ?? null;
        $clinicalStatusData = $data['clinicalStatus'] ?? null;
        $verificationStatusData = $data['verificationStatus'] ?? null;
        $codeData = $data['code'] ?? null;
        $patientData = $data['patient'] ?? null;
        $encounterData = $data['encounter'] ?? null;
        $recorderData = $data['recorder'] ?? null;
        $asserterData = $data['asserter'] ?? null;

        return new self(
            id: $data['id'] ?? null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: $data['language'] ?? null,
            identifier: array_values(array_map(
                static fn(array $i): Identifier => Identifier::fromArray($i),
                $data['identifier'] ?? [],
            )),
            clinicalStatus: $clinicalStatusData !== null
                ? CodeableConcept::fromArray($clinicalStatusData)
                : null,
            verificationStatus: $verificationStatusData !== null
                ? CodeableConcept::fromArray($verificationStatusData)
                : null,
            type: $data['type'] ?? null,
            category: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $data['category'] ?? [],
            )),
            criticality: $data['criticality'] ?? null,
            code: $codeData !== null ? CodeableConcept::fromArray($codeData) : null,
            patient: $patientData !== null ? Reference::fromArray($patientData) : null,
            encounter: $encounterData !== null ? Reference::fromArray($encounterData) : null,
            onsetDateTime: $data['onsetDateTime'] ?? null,
            recordedDate: $data['recordedDate'] ?? null,
            recorder: $recorderData !== null ? Reference::fromArray($recorderData) : null,
            asserter: $asserterData !== null ? Reference::fromArray($asserterData) : null,
            reaction: array_values(array_map(
                static fn(array $r): AllergyReaction => AllergyReaction::fromArray($r),
                $data['reaction'] ?? [],
            )),
        );
    }
}
