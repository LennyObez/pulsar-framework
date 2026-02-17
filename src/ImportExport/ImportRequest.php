<?php

declare(strict_types=1);

namespace Pulsar\ImportExport;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function in_array;
use function strlen;

/**
 * Request DTO for an import operation.
 */
#[Api(since: '1.0.0')]
final readonly class ImportRequest
{
    private const array VALID_FORMATS = ['json', 'csv', 'xml'];

    /**
     * @param string $content Raw import content (JSON string, CSV text, etc.)
     * @param string $format Input format ('json', 'csv', 'xml')
     * @param bool $dryRun When true, validate without persisting
     * @param DuplicateStrategy $duplicateStrategy How to handle duplicate entries
     * @param array<string, mixed> $options Provider-specific import options
     */
    public function __construct(
        public string $content,
        public string $format = 'json',
        public bool $dryRun = true,
        public DuplicateStrategy $duplicateStrategy = DuplicateStrategy::Skip,
        public array $options = [],
    ) {
        if (!in_array($this->format, self::VALID_FORMATS, true)) {
            throw new InvalidArgumentException(
                "Invalid import format '{$this->format}'. Valid formats: json, csv, xml",
            );
        }

        if (strlen($this->content) === 0) {
            throw new InvalidArgumentException('Import content must not be empty');
        }
    }
}
