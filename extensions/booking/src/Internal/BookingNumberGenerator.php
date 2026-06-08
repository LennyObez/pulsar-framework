<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Internal;

use Pulsar\Api\Internal;

use function date;
use function str_pad;

use const STR_PAD_LEFT;

/**
 * Generates sequential booking numbers in the format BKG-YYYY-NNNNNN.
 */
#[Internal]
final class BookingNumberGenerator
{
    private int $sequence = 0;

    /**
     * Generate the next booking number.
     */
    public function generate(): string
    {
        $this->sequence++;
        $year = date('Y');
        $number = str_pad((string) $this->sequence, 6, '0', STR_PAD_LEFT);

        return "BKG-{$year}-{$number}";
    }

    /**
     * Set the sequence counter (used when initializing from database).
     */
    public function setSequence(int $sequence): void
    {
        $this->sequence = $sequence;
    }
}
