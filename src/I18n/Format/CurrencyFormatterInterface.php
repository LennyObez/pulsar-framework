<?php

declare(strict_types=1);

namespace Pulsar\I18n\Format;

use Pulsar\Api\Api;

/**
 * Locale-aware currency formatting contract.
 * @api
 */
#[Api(since: '1.0.0')]
interface CurrencyFormatterInterface
{
    /**
     * Format a monetary amount with currency symbol.
     *
     * @param float $amount The monetary amount
     * @param string $currency ISO 4217 currency code (e.g. 'USD', 'EUR')
     * @param ?string $locale Override locale (null = current)
     */
    public function format(float $amount, string $currency, ?string $locale = null): string;
}
