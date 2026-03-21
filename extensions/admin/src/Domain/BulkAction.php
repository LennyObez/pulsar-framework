<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Domain;

use Pulsar\Api\Api;

/**
 * Defines a bulk action available on an admin resource.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BulkAction
{
    public function __construct(
        public string $name,
        public string $label,
        public bool $destructive = false,
        public bool $requireConfirmation = true,
        public ?string $icon = null,
    ) {}
}
