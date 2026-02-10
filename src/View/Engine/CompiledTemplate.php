<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable value object representing a compiled template artifact.
 *
 * Contains the path to the compiled PHP file, the content hash of the
 * source template (for cache validation), and the compilation timestamp.
 */
#[Api(since: '1.0.0')]
readonly class CompiledTemplate
{
    /**
     * @param string $compiledPath Absolute path to the compiled PHP file
     * @param string $sourceHash Content hash (SHA-256) of the source template
     * @param int $compiledAt Unix timestamp when the template was compiled
     */
    public function __construct(
        public string $compiledPath,
        public string $sourceHash,
        public int $compiledAt,
    ) {}

    /**
     * Check whether this compiled artifact is still valid for the given source hash.
     */
    #[NoDiscard]
    public function isValidFor(string $currentSourceHash): bool
    {
        return $this->sourceHash === $currentSourceHash;
    }
}
