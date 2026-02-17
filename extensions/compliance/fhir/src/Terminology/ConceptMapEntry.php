<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Terminology;

use Pulsar\Api\Api;

/**
 * A single entry in a ConceptMap, mapping one source code to one target code.
 *
 * @see https://www.hl7.org/fhir/conceptmap-definitions.html#ConceptMap.group.element.target
 */
#[Api(since: '1.0.0')]
final readonly class ConceptMapEntry
{
    public function __construct(
        public string $sourceCode,
        public string $targetCode,
        public string $equivalence = 'equivalent',
        public string $comment = '',
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = [
            'sourceCode' => $this->sourceCode,
            'targetCode' => $this->targetCode,
            'equivalence' => $this->equivalence,
        ];

        if ($this->comment !== '') {
            $data['comment'] = $this->comment;
        }

        return $data;
    }
}
