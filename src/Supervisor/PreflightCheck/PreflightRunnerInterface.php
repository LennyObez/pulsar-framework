<?php

declare(strict_types=1);

namespace Pulsar\Supervisor\PreflightCheck;

use Pulsar\Api\Api;

#[Api]
interface PreflightRunnerInterface
{
    /**
     * @return list<PreflightCheckResult>
     */
    public function run(): array;

    public function allPassed(): bool;
}
