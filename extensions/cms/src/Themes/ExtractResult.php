<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;

/**
 * Result of extracting a theme archive.
 *
 * @psalm-api Public DTO returned from ThemeArchiveExtractorInterface;
 *            consumed by ThemeManager during installation.
 */
#[Api(since: '1.0.0')]
final readonly class ExtractResult
{
    /**
     * @param bool $success Whether extraction completed successfully
     * @param int $fileCount Number of files extracted
     * @param list<string> $errors Extraction errors (empty on success)
     * @param list<string> $warnings Non-blocking extraction warnings
     */
    public function __construct(
        public bool $success,
        public int $fileCount,
        public array $errors = [],
        public array $warnings = [],
    ) {}
}
