<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Contracts;

use Pulsar\Api\Api;

/**
 * Contract for running external processes.
 *
 * Implementations handle timeout enforcement, output capping, and sanitization.
 * Studio components depend on this interface instead of directly importing
 * process execution classes from other extensions.
 */
#[Api(since: '1.0.0')]
interface ProcessRunnerInterface
{
    /**
     * Run a command and return the result.
     *
     * @param list<string> $command Command and arguments
     */
    public function run(array $command): ProcessResult;
}
