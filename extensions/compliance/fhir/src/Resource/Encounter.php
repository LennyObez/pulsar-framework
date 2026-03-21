<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function is_string;

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $metaData */
        $metaData = $data['meta'] ?? null;
        /** @var list<array<string, mixed>> $identifierList */
        $identifierList = $data['identifier'] ?? [];
        /** @var array<string, mixed>|null $classData */
        $classData = $data['class'] ?? null;
        /** @var list<array<string, mixed>> $typeList */
        $typeList = $data['type'] ?? [];
        /** @var array<string, mixed>|null $subjectData */
        $subjectData = $data['subject'] ?? null;
        /** @var array<string, mixed>|null $periodData */
        $periodData = $data['period'] ?? null;
        /** @var list<array<string, mixed>> $reasonCodeList */
        $reasonCodeList = $data['reasonCode'] ?? [];
        /** @var list<array<string, mixed>> $reasonRefList */
        $reasonRefList = $data['reasonReference'] ?? [];
        /** @var array<string, mixed>|null $serviceProviderData */
        $serviceProviderData = $data['serviceProvider'] ?? null;

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: is_string($data['language'] ?? null) ? $data['language'] : null,
            identifier: array_values(array_map(
                static fn(array $i): Identifier => Identifier::fromArray($i),
                $identifierList,
            )),
            status: is_string($data['status'] ?? null) ? $data['status'] : null,
            class_: $classData !== null ? Coding::fromArray($classData) : null,
            type: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $typeList,
            )),
            subject: $subjectData !== null ? Reference::fromArray($subjectData) : null,
            period: $periodData !== null ? Period::fromArray($periodData) : null,
            reasonCode: array_values(array_map(
                static fn(array $c): CodeableConcept => CodeableConcept::fromArray($c),
                $reasonCodeList,
            )),
            reasonReference: array_values(array_map(
                static fn(array $r): Reference => Reference::fromArray($r),
                $reasonRefList,
            )),
            serviceProvider: $serviceProviderData !== null
                ? Reference::fromArray($serviceProviderData)
                : null,
        );
    }
}
