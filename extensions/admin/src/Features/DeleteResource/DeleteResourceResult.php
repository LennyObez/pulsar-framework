<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\DeleteResource;

use Pulsar\Extension\Admin\Domain\ActionResult;

/**
 * Result DTO for deleting a resource record.
 */
final readonly class DeleteResourceResult
{
    public function __construct(
        public ActionResult $result,
    ) {}
}
