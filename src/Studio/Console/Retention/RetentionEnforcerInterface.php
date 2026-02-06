<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Retention;

use Pulsar\Api\Internal;

/**
 * Contract for enforcing retention policies on the evidence store.
 */
#[Internal]
interface RetentionEnforcerInterface
{
    /**
     * Enforce the retention policy.
     *
     * @return array{events_deleted: int, vacuum_run: bool}
     */
    public function enforce(): array;
}
