<?php

declare(strict_types=1);

namespace Pulsar\Console;

/**
 * Standard exit codes for CLI commands.
 */
enum ExitCode: int
{
    case Success = 0;
    case Error = 1;
    case Invalid = 2;

    /**
     * Check if this is a success exit code.
     */
    public function isSuccess(): bool
    {
        return $this === self::Success;
    }

    /**
     * Check if this is an error exit code.
     */
    public function isError(): bool
    {
        return $this !== self::Success;
    }
}
