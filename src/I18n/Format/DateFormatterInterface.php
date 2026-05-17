<?php

declare(strict_types=1);

namespace Pulsar\I18n\Format;

use DateTimeInterface;
use Pulsar\Api\Api;

/**
 * Locale-aware date/time formatting contract.
 * @api
 */
#[Api(since: '1.0.0')]
interface DateFormatterInterface
{
    /**
     * Format a date/time according to locale conventions.
     *
     * @param DateTimeInterface $date The date to format
     * @param ?string $locale Override locale (null = current)
     * @param int $dateType IntlDateFormatter date type constant
     * @param int $timeType IntlDateFormatter time type constant
     */
    public function format(DateTimeInterface $date, ?string $locale = null, int $dateType = 2, int $timeType = 3): string;
}
