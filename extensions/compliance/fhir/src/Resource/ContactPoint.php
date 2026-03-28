<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

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
     * @param array{
     *     system?: string|null,
     *     value?: string|null,
     *     use?: string|null,
     *     rank?: int|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            system: $data['system'] ?? null,
            value: $data['value'] ?? null,
            use: $data['use'] ?? null,
            rank: $data['rank'] ?? null,
        );
    }
}
