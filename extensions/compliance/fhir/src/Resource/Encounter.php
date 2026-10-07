<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function array_map;
use function array_values;

/**
 * An interaction between a patient and healthcare provider(s).
 *
 * @see https://www.hl7.org/fhir/encounter.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Encounter extends FhirResource
{
    /**
     * @param list<Identifier>     $identifier     Business identifiers
     * @param list<CodeableConcept> $type           Specific type of encounter
     * @param list<CodeableConcept> $reasonCode     Coded reason the encounter takes place
     * @param list<Reference>      $reasonReference Reference to conditions/procedures/observations
     */
    public function __construct(
        ?string $id = null,
        ?Meta $meta = null,
        ?string $language = null,
        public array $identifier = [],
        public ?string $status = null,
        public ?Coding $class_ = null,
        public array $type = [],
        public ?Reference $subject = null,
        public ?Period $period = null,
        public array $reasonCode = [],
        public array $reasonReference = [],
        public ?Reference $serviceProvider = null,
    ) {
        parent::__construct(ResourceType::Encounter, $id, $meta, $language);
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

        if ($this->class_ !== null) {
            $data['class'] = $this->class_->toArray();
        }

        if ($this->type !== []) {
            $data['type'] = array_map(
                static fn(CodeableConcept $c): array => $c->toArray(),
                $this->type,
            );
        }

        if ($this->subject !== null) {
            $data['subject'] = $this->subject->toArray();
        }

        if ($this->period !== null) {
            $data['period'] = $this->period->toArray();
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

        if ($this->serviceProvider !== null) {
            $data['serviceProvider'] = $this->serviceProvider->toArray();
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
     *     class?: array<string, mixed>|null,
     *     type?: list<array<string, mixed>>,
     *     subject?: array<string, mixed>|null,
     *     period?: array<string, mixed>|null,
     *     reasonCode?: list<array<string, mixed>>,
     *     reasonReference?: list<array<string, mixed>>,
     *     serviceProvider?: array<string, mixed>|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $metaData = $data['meta'] ?? null;
        $classData = $data['class'] ?? null;
        $subjectData = $data['subject'] ?? null;
        $periodData = $data['period'] ?? null;
        $serviceProviderData = $data['serviceProvider'] ?? null;

        return new self(
            id: $data['id'] ?? null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: $data['language'] ?? null,
            identifier: array_values(array_map(
                static fn(array $i): Identifier => Identifier::fromArray($i),
                $data['identifier'] ?? [],
            )),
            status: $data['status'] ?? null,
            class_: $classData !== null ? Coding::fromArray($classData) : null,
            type: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $data['type'] ?? [],
            )),
            subject: $subjectData !== null ? Reference::fromArray($subjectData) : null,
            period: $periodData !== null ? Period::fromArray($periodData) : null,
            reasonCode: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $data['reasonCode'] ?? [],
            )),
            reasonReference: array_values(array_map(
                static fn(array $r): Reference => Reference::fromArray($r),
                $data['reasonReference'] ?? [],
            )),
            serviceProvider: $serviceProviderData !== null
                ? Reference::fromArray($serviceProviderData)
                : null,
        );
    }
}
