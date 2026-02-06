<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Hygiene;

use Pulsar\Api\Internal;

use function error_clear_last;
use function ob_end_clean;
use function ob_get_level;
use function restore_error_handler;
use function restore_exception_handler;

/**
 * Resets PHP error state and output buffers between requests.
 */
#[Internal]
final class ErrorStateResetter
{
    public function reset(): void
    {
        // Clear last error
        error_clear_last();

        // Clear output buffers (leave the base level intact)
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        // Restore default error and exception handlers
        // Calling restore_ without a prior set_ is a no-op (safe)
        @restore_error_handler();
        @restore_exception_handler();
    }
}
