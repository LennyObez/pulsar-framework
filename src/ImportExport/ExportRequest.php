<?php

declare(strict_types=1);

namespace Pulsar\ImportExport;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function in_array;

/**
 * Request DTO for an export operation.
 */
#[Api(since: '1.0.0')]
final readonly class ExportRequest
{
    private const array VALID_FORMATS = ['json', 'csv', 'xml'];

    /**
     * @param string $format Output format ('json', 'csv', 'xml')
     * @param list<string> $entityTypes Entity types to include (empty = all)
     * @param bool $includePii Whether to include PII fields
     * @param array<string, mixed> $filters Provider-specific filter criteria
     */
    public function __construct(
        public string $format = 'json',
        public array $entityTypes = [],
        public bool $includePii = false,
        public array $filters = [],
    ) {
        if (!in_array($this->format, self::VALID_FORMATS, true)) {
            throw new InvalidArgumentException(
                "Invalid export format '{$this->format}'. Valid formats: json, csv, xml",
            );
        }
    }

    /**
     * @param array{
     *     format?: string,
     *     entity_types?: list<string>,
     *     include_pii?: bool,
     *     filters?: array<string, mixed>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            format: (string) ($data['format'] ?? 'json'),
            entityTypes: $data['entity_types'] ?? [],
            includePii: (bool) ($data['include_pii'] ?? false),
            filters: $data['filters'] ?? [],
        );
    }
}
