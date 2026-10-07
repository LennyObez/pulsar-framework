<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Terminology;

use Pulsar\Api\Api;

/**
 * A concept definition within a code system.
 *
 * @see https://www.hl7.org/fhir/codesystem-definitions.html#CodeSystem.concept
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConceptDefinition
{
    /**
     * @param array<string, string> $designations Alternative terms for the concept
     */
    public function __construct(
        public string $code,
        public string $display,
        public string $definition = '',
        public array $designations = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'code' => $this->code,
            'display' => $this->display,
        ];

        if ($this->definition !== '') {
            $data['definition'] = $this->definition;
        }

        if ($this->designations !== []) {
            $data['designation'] = [];
            foreach ($this->designations as $language => $value) {
                $data['designation'][] = [
                    'language' => $language,
                    'value' => $value,
                ];
            }
        }

        return $data;
    }
}
