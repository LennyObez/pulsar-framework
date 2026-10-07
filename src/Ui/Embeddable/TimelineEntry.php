<?php

declare(strict_types=1);

namespace Pulsar\Ui\Embeddable;

use Pulsar\Api\Api;

/**
 * Represents a single entry in a timeline component.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TimelineEntry
{
    public function __construct(
        public string $title,
        public string $description,
        public string $timestamp,
        public ?string $status = null,
        public ?string $icon = null,
    ) {}
}
