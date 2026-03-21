<?php

declare(strict_types=1);

namespace Pulsar\Rendering;

use Pulsar\Api\Api;

/**
 * Result of a static site generation pass.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StaticSiteResult
{
    /**
     * @param list<string> $paths Successfully rendered paths
     * @param list<string> $errors Error messages for failed renders
     */
    public function __construct(
        public int $pagesGenerated,
        public array $paths,
        public array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function isComplete(): bool
    {
        return $this->errors === [];
    }
}
