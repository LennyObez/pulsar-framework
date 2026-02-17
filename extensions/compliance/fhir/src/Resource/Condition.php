<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function is_string;

/**
 * A clinical condition, problem, diagnosis, or other event/situation/issue.
 *
 * @see https://www.hl7.org/fhir/condition.html
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $metaData */
        $metaData = $data['meta'] ?? null;
        /** @var list<array<string, mixed>> $identifierList */
        $identifierList = $data['identifier'] ?? [];
        /** @var array<string, mixed>|null $clinicalStatusData */
        $clinicalStatusData = $data['clinicalStatus'] ?? null;
        /** @var array<string, mixed>|null $verificationStatusData */
        $verificationStatusData = $data['verificationStatus'] ?? null;
        /** @var list<array<string, mixed>> $categoryList */
        $categoryList = $data['category'] ?? [];
        /** @var array<string, mixed>|null $severityData */
        $severityData = $data['severity'] ?? null;
        /** @var array<string, mixed>|null $codeData */
        $codeData = $data['code'] ?? null;
        /** @var array<string, mixed>|null $subjectData */
        $subjectData = $data['subject'] ?? null;
        /** @var array<string, mixed>|null $encounterData */
        $encounterData = $data['encounter'] ?? null;
        /** @var array<string, mixed>|null $recorderData */
        $recorderData = $data['recorder'] ?? null;
        /** @var array<string, mixed>|null $asserterData */
        $asserterData = $data['asserter'] ?? null;

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: is_string($data['language'] ?? null) ? $data['language'] : null,
            identifier: array_values(array_map(
                static fn(array $i): Identifier => Identifier::fromArray($i),
                $identifierList,
            )),
            clinicalStatus: $clinicalStatusData !== null
                ? CodeableConcept::fromArray($clinicalStatusData)
                : null,
            verificationStatus: $verificationStatusData !== null
                ? CodeableConcept::fromArray($verificationStatusData)
                : null,
            category: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $categoryList,
            )),
            severity: $severityData !== null ? CodeableConcept::fromArray($severityData) : null,
            code: $codeData !== null ? CodeableConcept::fromArray($codeData) : null,
            subject: $subjectData !== null ? Reference::fromArray($subjectData) : null,
            encounter: $encounterData !== null ? Reference::fromArray($encounterData) : null,
            onsetDateTime: is_string($data['onsetDateTime'] ?? null) ? $data['onsetDateTime'] : null,
            abatementDateTime: is_string($data['abatementDateTime'] ?? null) ? $data['abatementDateTime'] : null,
            recordedDate: is_string($data['recordedDate'] ?? null) ? $data['recordedDate'] : null,
            recorder: $recorderData !== null ? Reference::fromArray($recorderData) : null,
            asserter: $asserterData !== null ? Reference::fromArray($asserterData) : null,
        );
    }
}
