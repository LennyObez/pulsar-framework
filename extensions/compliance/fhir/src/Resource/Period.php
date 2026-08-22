<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * A time period defined by a start and end date/time.
 *
 * @see https://www.hl7.org/fhir/datatypes.html#Period
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Period
{
    public function __construct(
        public ?string $start = null,
        public ?string $end = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->start !== null) {
            $data['start'] = $this->start;
        }

        if ($this->end !== null) {
            $data['end'] = $this->end;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            start: Coerce::nullableString($data['start'] ?? null),
            end: Coerce::nullableString($data['end'] ?? null),
        );
    }
}
