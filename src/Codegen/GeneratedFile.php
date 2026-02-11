<?php

declare(strict_types=1);

namespace Pulsar\Codegen;

use Pulsar\Api\Api;

/**
 * Immutable value object representing a single generated file.
 */
#[Api(since: '1.0.0')]
final readonly class GeneratedFile
{
    public function __construct(
        public string $targetPath,
        public string $content,
        public OverwritePolicy $overwritePolicy = OverwritePolicy::Fail,
    ) {}
}
