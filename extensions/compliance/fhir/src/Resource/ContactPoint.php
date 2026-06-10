<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Contact details (phone, fax, email, etc.).
 *
 * @see https://www.hl7.org/fhir/datatypes.html#ContactPoint
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ContactPoint
{
    public function __construct(
        public ?string $system = null,
        public ?string $value = null,
        public ?string $use = null,
        public ?int $rank = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->system !== null) {
            $data['system'] = $this->system;
        }

        if ($this->value !== null) {
            $data['value'] = $this->value;
        }

        if ($this->use !== null) {
            $data['use'] = $this->use;
        }

        if ($this->rank !== null) {
            $data['rank'] = $this->rank;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rank = $data['rank'] ?? null;

        return new self(
            system: Coerce::nullableString($data['system'] ?? null),
            value: Coerce::nullableString($data['value'] ?? null),
            use: Coerce::nullableString($data['use'] ?? null),
            rank: $rank === null ? null : Coerce::int($rank, 0),
        );
    }
}
