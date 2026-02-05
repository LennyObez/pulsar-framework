<?php

declare(strict_types=1);

namespace Pulsar\Console;

use Pulsar\Api\Api;

/**
 * Output verbosity levels.
 */
#[Api]
enum Verbosity: int
{
    case Quiet = 0;
    case Normal = 1;
    case Verbose = 2;
    case Debug = 3;

    /**
     * Check if this level should show normal output.
     */
    public function showsNormal(): bool
    {
        return $this->value >= self::Normal->value;
    }

    /**
     * Check if this level should show verbose output.
     */
    public function showsVerbose(): bool
    {
        return $this->value >= self::Verbose->value;
    }

    /**
     * Check if this level should show debug output.
     */
    public function showsDebug(): bool
    {
        return $this->value >= self::Debug->value;
    }
}
