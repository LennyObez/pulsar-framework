<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\UpdateResource;

use Pulsar\Extension\Admin\Domain\ActionResult;

/**
 * Result DTO for updating a resource record.
 */
final readonly class UpdateResourceResult
{
    public function __construct(
        public ActionResult $result,
    ) {}
}
