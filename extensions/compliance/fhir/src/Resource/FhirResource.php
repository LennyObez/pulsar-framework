<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

/**
 * Base class for all FHIR resources.
 *
 * Provides the common FHIR resource fields: id, meta, and resourceType.
 * Subclasses add domain-specific fields per the FHIR R4/R5 specification.
 *
 * @see https://www.hl7.org/fhir/resource.html
 */
#[Api(since: '1.0.0')]
abstract readonly class FhirResource
{
    public function __construct(
        public ResourceType $resourceType,
        public ?string $id = null,
        public ?Meta $meta = null,
        public ?string $language = null,
    ) {}

    /**
     * Serialize the common resource fields.
     *
     * @return array<string, mixed>
     */
    protected function baseToArray(): array
    {
        $data = [
            'resourceType' => $this->resourceType->value,
        ];

        if ($this->id !== null) {
            $data['id'] = $this->id;
        }

        if ($this->meta !== null) {
            $data['meta'] = $this->meta->toArray();
        }

        if ($this->language !== null) {
            $data['language'] = $this->language;
        }

        return $data;
    }

    /**
     * Serialize the full resource to a FHIR-conformant array.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
