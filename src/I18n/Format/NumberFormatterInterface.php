<?php

declare(strict_types=1);

namespace Pulsar\I18n\Format;

use Pulsar\Api\Api;

/**
 * Locale-aware number formatting contract.
 */
#[Api(since: '1.0.0')]
interface NumberFormatterInterface
{
    /**
     * Format a number according to locale conventions.
     *
     * @param int|float $number The number to format
     * @param ?string $locale Override locale (null = current)
     */
    public function format(int|float $number, ?string $locale = null): string;
}
