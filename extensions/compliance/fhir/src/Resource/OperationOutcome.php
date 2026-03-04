<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function is_string;

/**
 * A collection of error, warning, or information messages that result from
 * a system action (typically a FHIR operation response for errors).
 *
 * @see https://www.hl7.org/fhir/operationoutcome.html
 */
#[Api(since: '1.0.0')]
final readonly class OperationOutcome extends FhirResource
{
    /**
     * @param list<OperationOutcomeIssue> $issue Issues associated with the action
     */
    public function __construct(
        ?string $id = null,
        ?Meta $meta = null,
        ?string $language = null,
        public array $issue = [],
    ) {
        parent::__construct(ResourceType::OperationOutcome, $id, $meta, $language);
    }

    #[Override]
    public function toArray(): array
    {
        $data = $this->baseToArray();

        $data['issue'] = array_map(
            static fn(OperationOutcomeIssue $i): array => $i->toArray(),
            $this->issue,
        );

        return $data;
    }

    /**
     * Create an OperationOutcome representing a single error.
     */
    public static function error(string $diagnostics, string $code = 'processing'): self
    {
        return new self(issue: [
            new OperationOutcomeIssue(
                severity: 'error',
                code: $code,
                diagnostics: $diagnostics,
            ),
        ]);
    }

    /**
     * Create an OperationOutcome for a "not found" error.
     */
    public static function notFound(string $resourceType, string $id): self
    {
        return new self(issue: [
            new OperationOutcomeIssue(
                severity: 'error',
                code: 'not-found',
                diagnostics: "$resourceType/$id not found",
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $metaData */
        $metaData = $data['meta'] ?? null;
        /** @var list<array<string, mixed>> $issueList */
        $issueList = $data['issue'] ?? [];

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: is_string($data['language'] ?? null) ? $data['language'] : null,
            issue: array_values(array_map(
                static fn(array $i): OperationOutcomeIssue => OperationOutcomeIssue::fromArray($i),
                $issueList,
            )),
        );
    }
}
