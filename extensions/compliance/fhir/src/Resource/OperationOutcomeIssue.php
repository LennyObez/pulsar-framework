<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

use function is_string;

/**
 * A single issue within an OperationOutcome.
 *
 * @see https://www.hl7.org/fhir/operationoutcome-definitions.html#OperationOutcome.issue
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class OperationOutcomeIssue
{
    /**
     * @param list<string> $location    Deprecated but still used: XPath to the element
     * @param list<string> $expression  FHIRPath to the element
     */
    public function __construct(
        public string $severity,
        public string $code,
        public ?CodeableConcept $details = null,
        public ?string $diagnostics = null,
        public array $location = [],
        public array $expression = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'severity' => $this->severity,
            'code' => $this->code,
        ];

        if ($this->details !== null) {
            $data['details'] = $this->details->toArray();
        }

        if ($this->diagnostics !== null) {
            $data['diagnostics'] = $this->diagnostics;
        }

        if ($this->location !== []) {
            $data['location'] = $this->location;
        }

        if ($this->expression !== []) {
            $data['expression'] = $this->expression;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $detailsData */
        $detailsData = $data['details'] ?? null;
        /** @var list<string> $location */
        $location = $data['location'] ?? [];
        /** @var list<string> $expression */
        $expression = $data['expression'] ?? [];

        return new self(
            severity: is_string($data['severity'] ?? null) ? $data['severity'] : 'error',
            code: is_string($data['code'] ?? null) ? $data['code'] : 'processing',
            details: $detailsData !== null ? CodeableConcept::fromArray($detailsData) : null,
            diagnostics: is_string($data['diagnostics'] ?? null) ? $data['diagnostics'] : null,
            location: $location,
            expression: $expression,
        );
    }
}
