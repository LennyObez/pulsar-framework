<?php

declare(strict_types=1);

namespace Pulsar\Tooling\Support;

use function count;
use function intdiv;
use function max;
use function min;
use function round;
use function sort;

/**
 * Summary statistics for a set of repeated measurements.
 *
 * A benchmark that reports one run reports the machine's mood as much as the code's
 * speed. These are the two numbers that make repeated runs interpretable: a centre
 * that one descheduled sample cannot move, and the width of the sample set so a
 * reader can tell a real difference between profiles from noise inside one.
 */
final readonly class SampleStatistics
{
    /**
     * Middle value of a sample set — robust where a mean is not.
     *
     * One descheduled run inflates a mean enough to invent a regression; the median
     * ignores it. That matters most on a developer machine, which is exactly where a
     * misleading number gets acted on.
     *
     * @param list<int> $values
     */
    public static function median(array $values): int
    {
        $count = count($values);

        if ($count === 0) {
            return 0;
        }

        sort($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : intdiv($values[$middle - 1] + $values[$middle], 2);
    }

    /**
     * Spread of a sample set as a percentage of its median.
     *
     * Published next to every headline figure so a reader can tell a 2% difference
     * between profiles from a 20% one inside a single profile. Fewer than two samples
     * have no spread to speak of — reporting 0.0 there says "not measured", which is
     * why callers gate on the sample count before trusting it.
     *
     * @param list<int> $values
     */
    public static function spreadPercent(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $mid = self::median($values);

        return $mid === 0 ? 0.0 : round(((max($values) - min($values)) / $mid) * 100, 1);
    }
}
