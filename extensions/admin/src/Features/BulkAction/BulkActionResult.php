<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\BulkAction;

use Pulsar\Extension\Admin\Domain\ActionResult;

/**
 * Result DTO for a bulk action.
 */
final readonly class BulkActionResult
{
    public function __construct(
        public ActionResult $result,
    ) {}
}
