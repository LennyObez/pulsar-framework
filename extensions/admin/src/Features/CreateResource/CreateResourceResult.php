<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\CreateResource;

use Pulsar\Extension\Admin\Domain\ActionResult;

/**
 * Result DTO for creating a resource record.
 */
final readonly class CreateResourceResult
{
    public function __construct(
        public ActionResult $result,
    ) {}
}
