<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function array_map;
use function array_values;

/**
 * An action that is performed on or for a patient.
 *
 * @see https://www.hl7.org/fhir/procedure.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Procedure extends FhirResource
{
    /**
     * @param list<Identifier>     $identifier      Business identifiers
     * @param list<CodeableConcept> $reasonCode      Why the procedure was performed
     * @param list<Reference>      $reasonReference  Justification for the procedure
     * @param list<Reference>      $report           Procedure reports
     */
    public function __construct(
        ?string $id = null,
        ?Meta $meta = null,
        ?string $language = null,
        public array $identifier = [],
        public ?string $status = null,
        public ?CodeableConcept $statusReason = null,
        public ?CodeableConcept $category = null,
        public ?CodeableConcept $code = null,
        public ?Reference $subject = null,
        public ?Reference $encounter = null,
        public ?string $performedDateTime = null,
        public ?Period $performedPeriod = null,
        public ?Reference $recorder = null,
        public ?Reference $asserter = null,
        public array $reasonCode = [],
        public array $reasonReference = [],
        public ?CodeableConcept $outcome = null,
        public array $report = [],
    ) {
        parent::__construct(ResourceType::Procedure, $id, $meta, $language);
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

        if ($this->statusReason !== null) {
            $data['statusReason'] = $this->statusReason->toArray();
        }

        if ($this->category !== null) {
            $data['category'] = $this->category->toArray();
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

        if ($this->performedDateTime !== null) {
            $data['performedDateTime'] = $this->performedDateTime;
        }

        if ($this->performedPeriod !== null) {
            $data['performedPeriod'] = $this->performedPeriod->toArray();
        }

        if ($this->recorder !== null) {
            $data['recorder'] = $this->recorder->toArray();
        }

        if ($this->asserter !== null) {
            $data['asserter'] = $this->asserter->toArray();
        }

        if ($this->reasonCode !== []) {
            $data['reasonCode'] = array_map(
                static fn(CodeableConcept $c): array => $c->toArray(),
                $this->reasonCode,
            );
        }

        if ($this->reasonReference !== []) {
            $data['reasonReference'] = array_map(
                static fn(Reference $r): array => $r->toArray(),
                $this->reasonReference,
            );
        }

        if ($this->outcome !== null) {
            $data['outcome'] = $this->outcome->toArray();
        }

        if ($this->report !== []) {
            $data['report'] = array_map(
                static fn(Reference $r): array => $r->toArray(),
                $this->report,
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
     *     status?: string|null,
     *     statusReason?: array<string, mixed>|null,
     *     category?: array<string, mixed>|null,
     *     code?: array<string, mixed>|null,
     *     subject?: array<string, mixed>|null,
     *     encounter?: array<string, mixed>|null,
     *     performedDateTime?: string|null,
     *     performedPeriod?: array<string, mixed>|null,
     *     recorder?: array<string, mixed>|null,
     *     asserter?: array<string, mixed>|null,
     *     reasonCode?: list<array<string, mixed>>,
     *     reasonReference?: list<array<string, mixed>>,
     *     outcome?: array<string, mixed>|null,
     *     report?: list<array<string, mixed>>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $metaData = $data['meta'] ?? null;
        $statusReasonData = $data['statusReason'] ?? null;
        $categoryData = $data['category'] ?? null;
        $codeData = $data['code'] ?? null;
        $subjectData = $data['subject'] ?? null;
        $encounterData = $data['encounter'] ?? null;
        $performedPeriodData = $data['performedPeriod'] ?? null;
        $recorderData = $data['recorder'] ?? null;
        $asserterData = $data['asserter'] ?? null;
        $outcomeData = $data['outcome'] ?? null;

        return new self(
            id: $data['id'] ?? null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: $data['language'] ?? null,
            identifier: array_values(array_map(
                static fn(array $i): Identifier => Identifier::fromArray($i),
                $data['identifier'] ?? [],
            )),
            status: $data['status'] ?? null,
            statusReason: $statusReasonData !== null
                ? CodeableConcept::fromArray($statusReasonData)
                : null,
            category: $categoryData !== null ? CodeableConcept::fromArray($categoryData) : null,
            code: $codeData !== null ? CodeableConcept::fromArray($codeData) : null,
            subject: $subjectData !== null ? Reference::fromArray($subjectData) : null,
            encounter: $encounterData !== null ? Reference::fromArray($encounterData) : null,
            performedDateTime: $data['performedDateTime'] ?? null,
            performedPeriod: $performedPeriodData !== null
                ? Period::fromArray($performedPeriodData)
                : null,
            recorder: $recorderData !== null ? Reference::fromArray($recorderData) : null,
            asserter: $asserterData !== null ? Reference::fromArray($asserterData) : null,
            reasonCode: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $data['reasonCode'] ?? [],
            )),
            reasonReference: array_values(array_map(
                static fn(array $r): Reference => Reference::fromArray($r),
                $data['reasonReference'] ?? [],
            )),
            outcome: $outcomeData !== null ? CodeableConcept::fromArray($outcomeData) : null,
            report: array_values(array_map(
                static fn(array $r): Reference => Reference::fromArray($r),
                $data['report'] ?? [],
            )),
        );
    }
}
