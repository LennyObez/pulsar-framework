<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

/**
 * A reference from one resource to another.
 *
 * @see https://www.hl7.org/fhir/references.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Reference
{
    public function __construct(
        public ?string $reference = null,
        public ?string $type = null,
        public ?string $display = null,
        public ?Identifier $identifier = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->reference !== null) {
            $data['reference'] = $this->reference;
        }

        if ($this->type !== null) {
            $data['type'] = $this->type;
        }

        if ($this->display !== null) {
            $data['display'] = $this->display;
        }

        if ($this->identifier !== null) {
            $data['identifier'] = $this->identifier->toArray();
        }

        return $data;
    }

    /**
     * @param array{
     *     reference?: string|null,
     *     type?: string|null,
     *     display?: string|null,
     *     identifier?: array<string, mixed>|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $identifierData = $data['identifier'] ?? null;

        return new self(
            reference: $data['reference'] ?? null,
            type: $data['type'] ?? null,
            display: $data['display'] ?? null,
            identifier: $identifierData !== null ? Identifier::fromArray($identifierData) : null,
        );
    }
}
