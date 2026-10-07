<?php

declare(strict_types=1);

namespace Pulsar\Codegen;

use Pulsar\Api\Api;

use function array_map;
use function sort;

use const SORT_STRING;

/**
 * Reports which files would be overwritten during code generation.
 *
 * Output is deterministic: same inputs always produce the same sorted conflict list.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConflictReporter
{
    /**
     * Return a sorted list of file paths that would conflict.
     *
     * @return list<string> Sorted conflict paths
     */
    public function report(GeneratedFileSet $fileSet): array
    {
        $conflicts = $fileSet->conflicts();

        $paths = array_map(
            static fn(GeneratedFile $file): string => $file->targetPath,
            $conflicts,
        );

        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Whether there are any conflicts.
     */
    public function hasConflicts(GeneratedFileSet $fileSet): bool
    {
        return $fileSet->hasConflicts();
    }
}
