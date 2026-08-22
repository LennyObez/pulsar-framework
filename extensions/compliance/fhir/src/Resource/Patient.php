<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function array_map;
use function array_values;

/**
 * Demographics and administrative information about an individual receiving care.
 *
 * @see https://www.hl7.org/fhir/patient.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Patient extends FhirResource
{
    /**
     * @param list<Identifier> $identifier Business identifiers (MRN, SSN, etc.)
     * @param list<HumanName>  $name       Names associated with the patient
     * @param list<ContactPoint> $telecom  Contact details (phone, email, etc.)
     */
    public function __construct(
        ?string $id = null,
        ?Meta $meta = null,
        ?string $language = null,
        public array $identifier = [],
        public ?bool $active = null,
        public array $name = [],
        public array $telecom = [],
        public ?string $gender = null,
        public ?string $birthDate = null,
        public ?bool $deceasedBoolean = null,
        public ?string $deceasedDateTime = null,
        public ?Reference $managingOrganization = null,
    ) {
        parent::__construct(ResourceType::Patient, $id, $meta, $language);
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

        if ($this->active !== null) {
            $data['active'] = $this->active;
        }

        if ($this->name !== []) {
            $data['name'] = array_map(
                static fn(HumanName $n): array => $n->toArray(),
                $this->name,
            );
        }

        if ($this->telecom !== []) {
            $data['telecom'] = array_map(
                static fn(ContactPoint $t): array => $t->toArray(),
                $this->telecom,
            );
        }

        if ($this->gender !== null) {
            $data['gender'] = $this->gender;
        }

        if ($this->birthDate !== null) {
            $data['birthDate'] = $this->birthDate;
        }

        if ($this->deceasedBoolean !== null) {
            $data['deceasedBoolean'] = $this->deceasedBoolean;
        }

        if ($this->deceasedDateTime !== null) {
            $data['deceasedDateTime'] = $this->deceasedDateTime;
        }

        if ($this->managingOrganization !== null) {
            $data['managingOrganization'] = $this->managingOrganization->toArray();
        }

        return $data;
    }

    /**
     * @param array{
     *     id?: string|null,
     *     meta?: array<string, mixed>|null,
     *     language?: string|null,
     *     identifier?: list<array<string, mixed>>,
     *     active?: bool|null,
     *     name?: list<array<string, mixed>>,
     *     telecom?: list<array<string, mixed>>,
     *     gender?: string|null,
     *     birthDate?: string|null,
     *     deceasedBoolean?: bool|null,
     *     deceasedDateTime?: string|null,
     *     managingOrganization?: array<string, mixed>|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $metaData = $data['meta'] ?? null;
        $managingOrgData = $data['managingOrganization'] ?? null;

        return new self(
            id: $data['id'] ?? null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: $data['language'] ?? null,
            identifier: array_values(array_map(
                static fn(array $i): Identifier => Identifier::fromArray($i),
                $data['identifier'] ?? [],
            )),
            active: $data['active'] ?? null,
            name: array_values(array_map(
                static fn(array $n): HumanName => HumanName::fromArray($n),
                $data['name'] ?? [],
            )),
            telecom: array_values(array_map(
                static fn(array $t): ContactPoint => ContactPoint::fromArray($t),
                $data['telecom'] ?? [],
            )),
            gender: $data['gender'] ?? null,
            birthDate: $data['birthDate'] ?? null,
            deceasedBoolean: $data['deceasedBoolean'] ?? null,
            deceasedDateTime: $data['deceasedDateTime'] ?? null,
            managingOrganization: $managingOrgData !== null ? Reference::fromArray($managingOrgData) : null,
        );
    }
}
