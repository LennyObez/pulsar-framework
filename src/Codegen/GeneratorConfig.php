<?php

declare(strict_types=1);

namespace Pulsar\Codegen;

use Pulsar\Api\Api;

/**
 * Configuration DTO for code generators.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class GeneratorConfig
{
    public function __construct(
        public string $outputBaseDirectory,
        public string $namespacePrefix = 'App',
        public bool $force = false,
    ) {}
}
