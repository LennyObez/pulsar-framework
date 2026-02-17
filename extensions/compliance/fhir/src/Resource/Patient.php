<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function is_bool;
use function is_string;

/**
 * Demographics and administrative information about an individual receiving care.
 *
 * @see https://www.hl7.org/fhir/patient.html
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $metaData */
        $metaData = $data['meta'] ?? null;
        /** @var list<array<string, mixed>> $identifierList */
        $identifierList = $data['identifier'] ?? [];
        /** @var list<array<string, mixed>> $nameList */
        $nameList = $data['name'] ?? [];
        /** @var list<array<string, mixed>> $telecomList */
        $telecomList = $data['telecom'] ?? [];
        /** @var array<string, mixed>|null $managingOrgData */
        $managingOrgData = $data['managingOrganization'] ?? null;

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: is_string($data['language'] ?? null) ? $data['language'] : null,
            identifier: array_values(array_map(
                static fn(array $i): Identifier => Identifier::fromArray($i),
                $identifierList,
            )),
            active: is_bool($data['active'] ?? null) ? $data['active'] : null,
            name: array_values(array_map(
                static fn(array $n): HumanName => HumanName::fromArray($n),
                $nameList,
            )),
            telecom: array_values(array_map(
                static fn(array $t): ContactPoint => ContactPoint::fromArray($t),
                $telecomList,
            )),
            gender: is_string($data['gender'] ?? null) ? $data['gender'] : null,
            birthDate: is_string($data['birthDate'] ?? null) ? $data['birthDate'] : null,
            deceasedBoolean: is_bool($data['deceasedBoolean'] ?? null) ? $data['deceasedBoolean'] : null,
            deceasedDateTime: is_string($data['deceasedDateTime'] ?? null) ? $data['deceasedDateTime'] : null,
            managingOrganization: $managingOrgData !== null ? Reference::fromArray($managingOrgData) : null,
        );
    }
}
