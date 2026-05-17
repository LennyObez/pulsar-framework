<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Rest;

use Pulsar\Api\Api;

/**
 * Represents a FHIR search parameter definition.
 *
 * @see https://www.hl7.org/fhir/searchparameter.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SearchParameter
{
    public function __construct(
        public string $name,
        public string $type,
        public string $description,
        public ?string $expression = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
        ];

        if ($this->expression !== null) {
            $data['expression'] = $this->expression;
        }

        return $data;
    }
}
