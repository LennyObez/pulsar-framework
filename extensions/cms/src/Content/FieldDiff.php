<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Represents a single field-level change between two revisions.
 */
#[Api(since: '1.0.0')]
final readonly class FieldDiff
{
    public function __construct(
        public string $field,
        public ?string $from,
        public ?string $to,
    ) {}
}
