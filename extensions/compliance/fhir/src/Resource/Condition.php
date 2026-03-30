<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function array_map;
use function array_values;

/**
 * A clinical condition, problem, diagnosis, or other event/situation/issue.
 *
 * @see https://www.hl7.org/fhir/condition.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Condition extends FhirResource
{
    /**
     * @param list<Identifier>     $identifier Business identifiers
     * @param list<CodeableConcept> $category   Category of the condition (problem-list-item | encounter-diagnosis)
     */
    public function __construct(
        ?string $id = null,
        ?Meta $meta = null,
        ?string $language = null,
        public array $identifier = [],
        public ?CodeableConcept $clinicalStatus = null,
        public ?CodeableConcept $verificationStatus = null,
        public array $category = [],
        public ?CodeableConcept $severity = null,
        public ?CodeableConcept $code = null,
        public ?Reference $subject = null,
        public ?Reference $encounter = null,
        public ?string $onsetDateTime = null,
        public ?string $abatementDateTime = null,
        public ?string $recordedDate = null,
        public ?Reference $recorder = null,
        public ?Reference $asserter = null,
    ) {
        parent::__construct(ResourceType::Condition, $id, $meta, $language);
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

        if ($this->category !== []) {
            $data['category'] = array_map(
                static fn(CodeableConcept $c): array => $c->toArray(),
                $this->category,
            );
        }

        if ($this->severity !== null) {
            $data['severity'] = $this->severity->toArray();
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

        if ($this->onsetDateTime !== null) {
            $data['onsetDateTime'] = $this->onsetDateTime;
        }

        if ($this->abatementDateTime !== null) {
            $data['abatementDateTime'] = $this->abatementDateTime;
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
     *     category?: list<array<string, mixed>>,
     *     severity?: array<string, mixed>|null,
     *     code?: array<string, mixed>|null,
     *     subject?: array<string, mixed>|null,
     *     encounter?: array<string, mixed>|null,
     *     onsetDateTime?: string|null,
     *     abatementDateTime?: string|null,
     *     recordedDate?: string|null,
     *     recorder?: array<string, mixed>|null,
     *     asserter?: array<string, mixed>|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $metaData = $data['meta'] ?? null;
        $clinicalStatusData = $data['clinicalStatus'] ?? null;
        $verificationStatusData = $data['verificationStatus'] ?? null;
        $severityData = $data['severity'] ?? null;
        $codeData = $data['code'] ?? null;
        $subjectData = $data['subject'] ?? null;
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
            category: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $data['category'] ?? [],
            )),
            severity: $severityData !== null ? CodeableConcept::fromArray($severityData) : null,
            code: $codeData !== null ? CodeableConcept::fromArray($codeData) : null,
            subject: $subjectData !== null ? Reference::fromArray($subjectData) : null,
            encounter: $encounterData !== null ? Reference::fromArray($encounterData) : null,
            onsetDateTime: $data['onsetDateTime'] ?? null,
            abatementDateTime: $data['abatementDateTime'] ?? null,
            recordedDate: $data['recordedDate'] ?? null,
            recorder: $recorderData !== null ? Reference::fromArray($recorderData) : null,
            asserter: $asserterData !== null ? Reference::fromArray($asserterData) : null,
        );
    }
}
