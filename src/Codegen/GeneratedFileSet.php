<?php

declare(strict_types=1);

namespace Pulsar\Codegen;

use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function is_file;
use function usort;

/**
 * Immutable collection of generated files with conflict detection.
 */
#[Api(since: '1.0.0')]
final readonly class GeneratedFileSet
{
    /** @var list<GeneratedFile> */
    private array $files;

    /**
     * @param list<GeneratedFile> $files
     */
    public function __construct(array $files = [])
    {
        // Ensure deterministic ordering by target path
        $sorted = $files;
        usort($sorted, static fn(GeneratedFile $a, GeneratedFile $b): int => $a->targetPath <=> $b->targetPath);
        $this->files = $sorted;
    }

    /**
     * @return list<GeneratedFile>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Return files whose target paths already exist on disk.
     *
     * @return list<GeneratedFile>
     */
    public function conflicts(): array
    {
        return array_values(
            array_filter(
                $this->files,
                static fn(GeneratedFile $file): bool => is_file($file->targetPath),
            ),
        );
    }

    /**
     * Whether any generated file targets an already-existing path.
     */
    public function hasConflicts(): bool
    {
        return $this->conflicts() !== [];
    }
}
