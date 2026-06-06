<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Internal\Diagnostics;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Internal\Subprocess\SubprocessRunner;
use Pulsar\Extension\Studio\Contracts\ProcessResult;
use Pulsar\Extension\Studio\Contracts\ProcessRunnerInterface;

/**
 * Adapts the MCP SubprocessRunner to the Studio ProcessRunnerInterface.
 *
 * This adapter lives in the composition root (Internal namespace) and is the
 * only place that references the McpServer internal class. All other Studio
 * code depends on the public ProcessRunnerInterface contract.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class SubprocessRunnerAdapter implements ProcessRunnerInterface
{
    public function __construct(
        private SubprocessRunner $runner,
    ) {}

    #[Override]
    public function run(array $command): ProcessResult
    {
        $result = $this->runner->run($command);

        return new ProcessResult(
            output: $result->textContent,
            isError: $result->isError,
        );
    }
}
