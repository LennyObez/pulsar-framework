<?php

declare(strict_types=1);

namespace Pulsar\Ui\Embeddable;

use Pulsar\Api\Api;

/**
 * Column definition for DataTableComponent.
 */
#[Api(since: '1.0.0')]
final readonly class ColumnDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $sortable = true,
        public bool $filterable = true,
        public ?string $format = null,
    ) {}
}
