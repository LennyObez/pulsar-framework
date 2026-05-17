<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

use function is_string;

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
        return new self(
            system: is_string($data['system'] ?? null) ? $data['system'] : null,
            value: is_string($data['value'] ?? null) ? $data['value'] : null,
            use: is_string($data['use'] ?? null) ? $data['use'] : null,
            rank: isset($data['rank']) && is_numeric($data['rank']) ? (int) $data['rank'] : null,
        );
    }
}
