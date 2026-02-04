<?php

declare(strict_types=1);

namespace Pulsar\Supervisor\PreflightCheck;

use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
interface PreflightRunnerInterface
{
    /**
     * @return list<PreflightCheckResult>
     */
    public function run(): array;

    public function allPassed(): bool;
}
