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
    /**
     * Buffer nesting level that belongs to the host, not to a request.
     */
    private readonly int $baseBufferLevel;

    /**
     * @param int|null $baseBufferLevel Nesting level to unwind to, or null to capture the
     *                                  level in force now. Constructed from the composition
     *                                  root before the worker serves anything, so "now" is
     *                                  the host's own level: the buffer `output_buffering`
     *                                  opens at startup, or one an embedding SAPI owns.
     */
    public function __construct(?int $baseBufferLevel = null)
    {
        $this->baseBufferLevel = $baseBufferLevel ?? ob_get_level();
    }

    public function reset(): void
    {
        // Clear last error
        error_clear_last();

        // Discard the buffers this request opened, and only those. Unwinding to
        // zero would take the host's base buffer with it — after the first
        // request the worker would silently stop buffering the way php.ini asked
        // it to, which is the kind of drift that shows up as truncated output
        // several requests later.
        while (ob_get_level() > $this->baseBufferLevel) {
            if (@ob_end_clean() === false) {
                // A buffer opened without PHP_OUTPUT_HANDLER_REMOVABLE refuses to
                // go. Leaving it is a leak; looping on it hangs the worker for
                // good, so the leak is the lesser failure.
                break;
            }
        }

        // Restore default error and exception handlers
        // Calling restore_ without a prior set_ is a no-op (safe)
        @restore_error_handler();
        @restore_exception_handler();
    }
}
