<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

use function is_string;

/**
 * A reference from one resource to another.
 *
 * @see https://www.hl7.org/fhir/references.html
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $identifierData */
        $identifierData = $data['identifier'] ?? null;

        return new self(
            reference: is_string($data['reference'] ?? null) ? $data['reference'] : null,
            type: is_string($data['type'] ?? null) ? $data['type'] : null,
            display: is_string($data['display'] ?? null) ? $data['display'] : null,
            identifier: $identifierData !== null ? Identifier::fromArray($identifierData) : null,
        );
    }
}
