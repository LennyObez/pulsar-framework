<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function is_string;

/**
 * An order or request for supply of medication and administration instructions.
 *
 * @see https://www.hl7.org/fhir/medicationrequest.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MedicationRequest extends FhirResource
{
    /**
     * @param list<Identifier>     $identifier   Business identifiers
     * @param list<CodeableConcept> $reasonCode   Reason for the medication request
     * @param list<Reference>      $reasonReference Condition or observation supporting the request
     */
    public function __construct(
        ?string $id = null,
        ?Meta $meta = null,
        ?string $language = null,
        public array $identifier = [],
        public ?string $status = null,
        public ?CodeableConcept $statusReason = null,
        public ?string $intent = null,
        public ?CodeableConcept $medicationCodeableConcept = null,
        public ?Reference $medicationReference = null,
        public ?Reference $subject = null,
        public ?Reference $encounter = null,
        public ?string $authoredOn = null,
        public ?Reference $requester = null,
        public array $reasonCode = [],
        public array $reasonReference = [],
        public ?string $priority = null,
    ) {
        parent::__construct(ResourceType::MedicationRequest, $id, $meta, $language);
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

        if ($this->intent !== null) {
            $data['intent'] = $this->intent;
        }

        if ($this->medicationCodeableConcept !== null) {
            $data['medicationCodeableConcept'] = $this->medicationCodeableConcept->toArray();
        }

        if ($this->medicationReference !== null) {
            $data['medicationReference'] = $this->medicationReference->toArray();
        }

        if ($this->subject !== null) {
            $data['subject'] = $this->subject->toArray();
        }

        if ($this->encounter !== null) {
            $data['encounter'] = $this->encounter->toArray();
        }

        if ($this->authoredOn !== null) {
            $data['authoredOn'] = $this->authoredOn;
        }

        if ($this->requester !== null) {
            $data['requester'] = $this->requester->toArray();
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

        if ($this->priority !== null) {
            $data['priority'] = $this->priority;
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
        /** @var array<string, mixed>|null $statusReasonData */
        $statusReasonData = $data['statusReason'] ?? null;
        /** @var array<string, mixed>|null $medConceptData */
        $medConceptData = $data['medicationCodeableConcept'] ?? null;
        /** @var array<string, mixed>|null $medRefData */
        $medRefData = $data['medicationReference'] ?? null;
        /** @var array<string, mixed>|null $subjectData */
        $subjectData = $data['subject'] ?? null;
        /** @var array<string, mixed>|null $encounterData */
        $encounterData = $data['encounter'] ?? null;
        /** @var array<string, mixed>|null $requesterData */
        $requesterData = $data['requester'] ?? null;
        /** @var list<array<string, mixed>> $reasonCodeList */
        $reasonCodeList = $data['reasonCode'] ?? [];
        /** @var list<array<string, mixed>> $reasonRefList */
        $reasonRefList = $data['reasonReference'] ?? [];

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: is_string($data['language'] ?? null) ? $data['language'] : null,
            identifier: array_values(array_map(
                static fn(array $i): Identifier => Identifier::fromArray($i),
                $identifierList,
            )),
            status: is_string($data['status'] ?? null) ? $data['status'] : null,
            statusReason: $statusReasonData !== null
                ? CodeableConcept::fromArray($statusReasonData)
                : null,
            intent: is_string($data['intent'] ?? null) ? $data['intent'] : null,
            medicationCodeableConcept: $medConceptData !== null
                ? CodeableConcept::fromArray($medConceptData)
                : null,
            medicationReference: $medRefData !== null ? Reference::fromArray($medRefData) : null,
            subject: $subjectData !== null ? Reference::fromArray($subjectData) : null,
            encounter: $encounterData !== null ? Reference::fromArray($encounterData) : null,
            authoredOn: is_string($data['authoredOn'] ?? null) ? $data['authoredOn'] : null,
            requester: $requesterData !== null ? Reference::fromArray($requesterData) : null,
            reasonCode: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $reasonCodeList,
            )),
            reasonReference: array_values(array_map(
                static fn(array $r): Reference => Reference::fromArray($r),
                $reasonRefList,
            )),
            priority: is_string($data['priority'] ?? null) ? $data['priority'] : null,
        );
    }
}
