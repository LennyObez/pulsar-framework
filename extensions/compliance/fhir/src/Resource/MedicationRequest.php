<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function array_map;
use function array_values;

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
     * @param array{
     *     id?: string|null,
     *     meta?: array<string, mixed>|null,
     *     language?: string|null,
     *     identifier?: list<array<string, mixed>>,
     *     status?: string|null,
     *     statusReason?: array<string, mixed>|null,
     *     intent?: string|null,
     *     medicationCodeableConcept?: array<string, mixed>|null,
     *     medicationReference?: array<string, mixed>|null,
     *     subject?: array<string, mixed>|null,
     *     encounter?: array<string, mixed>|null,
     *     authoredOn?: string|null,
     *     requester?: array<string, mixed>|null,
     *     reasonCode?: list<array<string, mixed>>,
     *     reasonReference?: list<array<string, mixed>>,
     *     priority?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $metaData = $data['meta'] ?? null;
        $statusReasonData = $data['statusReason'] ?? null;
        $medConceptData = $data['medicationCodeableConcept'] ?? null;
        $medRefData = $data['medicationReference'] ?? null;
        $subjectData = $data['subject'] ?? null;
        $encounterData = $data['encounter'] ?? null;
        $requesterData = $data['requester'] ?? null;

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
            intent: $data['intent'] ?? null,
            medicationCodeableConcept: $medConceptData !== null
                ? CodeableConcept::fromArray($medConceptData)
                : null,
            medicationReference: $medRefData !== null ? Reference::fromArray($medRefData) : null,
            subject: $subjectData !== null ? Reference::fromArray($subjectData) : null,
            encounter: $encounterData !== null ? Reference::fromArray($encounterData) : null,
            authoredOn: $data['authoredOn'] ?? null,
            requester: $requesterData !== null ? Reference::fromArray($requesterData) : null,
            reasonCode: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $data['reasonCode'] ?? [],
            )),
            reasonReference: array_values(array_map(
                static fn(array $r): Reference => Reference::fromArray($r),
                $data['reasonReference'] ?? [],
            )),
            priority: $data['priority'] ?? null,
        );
    }
}
