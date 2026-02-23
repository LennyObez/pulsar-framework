<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Template;

use Pulsar\Api\Api;

/**
 * Immutable value object representing a single template variable.
 */
#[Api(since: '1.0.0')]
final readonly class TemplateVariable
{
    public function __construct(
        public string $name,
        public string $value,
    ) {}
}
