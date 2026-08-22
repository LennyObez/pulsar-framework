<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function array_map;
use function array_values;

/**
 * The findings and interpretation of diagnostic tests performed on patients.
 *
 * @see https://www.hl7.org/fhir/diagnosticreport.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DiagnosticReport extends FhirResource
{
    /**
     * @param list<Identifier>     $identifier Business identifiers
     * @param list<CodeableConcept> $category   Service category
     * @param list<Reference>      $performer   Responsible diagnostic service
     * @param list<Reference>      $result      Observations that are part of this report
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
        public ?Period $effectivePeriod = null,
        public ?string $issued = null,
        public array $performer = [],
        public array $result = [],
        public ?string $conclusion = null,
        public ?CodeableConcept $conclusionCode = null,
    ) {
        parent::__construct(ResourceType::DiagnosticReport, $id, $meta, $language);
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

        if ($this->effectivePeriod !== null) {
            $data['effectivePeriod'] = $this->effectivePeriod->toArray();
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

        if ($this->result !== []) {
            $data['result'] = array_map(
                static fn(Reference $r): array => $r->toArray(),
                $this->result,
            );
        }

        if ($this->conclusion !== null) {
            $data['conclusion'] = $this->conclusion;
        }

        if ($this->conclusionCode !== null) {
            $data['conclusionCode'] = $this->conclusionCode->toArray();
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
     *     category?: list<array<string, mixed>>,
     *     code?: array<string, mixed>|null,
     *     subject?: array<string, mixed>|null,
     *     encounter?: array<string, mixed>|null,
     *     effectiveDateTime?: string|null,
     *     effectivePeriod?: array<string, mixed>|null,
     *     issued?: string|null,
     *     performer?: list<array<string, mixed>>,
     *     result?: list<array<string, mixed>>,
     *     conclusion?: string|null,
     *     conclusionCode?: array<string, mixed>|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $metaData = $data['meta'] ?? null;
        $codeData = $data['code'] ?? null;
        $subjectData = $data['subject'] ?? null;
        $encounterData = $data['encounter'] ?? null;
        $effectivePeriodData = $data['effectivePeriod'] ?? null;
        $conclusionCodeData = $data['conclusionCode'] ?? null;

        return new self(
            id: $data['id'] ?? null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: $data['language'] ?? null,
            identifier: array_values(array_map(
                static fn(array $i): Identifier => Identifier::fromArray($i),
                $data['identifier'] ?? [],
            )),
            status: $data['status'] ?? null,
            category: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $data['category'] ?? [],
            )),
            code: $codeData !== null ? CodeableConcept::fromArray($codeData) : null,
            subject: $subjectData !== null ? Reference::fromArray($subjectData) : null,
            encounter: $encounterData !== null ? Reference::fromArray($encounterData) : null,
            effectiveDateTime: $data['effectiveDateTime'] ?? null,
            effectivePeriod: $effectivePeriodData !== null
                ? Period::fromArray($effectivePeriodData)
                : null,
            issued: $data['issued'] ?? null,
            performer: array_values(array_map(
                static fn(array $r): Reference => Reference::fromArray($r),
                $data['performer'] ?? [],
            )),
            result: array_values(array_map(
                static fn(array $r): Reference => Reference::fromArray($r),
                $data['result'] ?? [],
            )),
            conclusion: $data['conclusion'] ?? null,
            conclusionCode: $conclusionCodeData !== null
                ? CodeableConcept::fromArray($conclusionCodeData)
                : null,
        );
    }
}
