<?php

declare(strict_types=1);

namespace Pulsar\I18n\Format;

use NumberFormatter;
use Pulsar\Api\Internal;
use Pulsar\I18n\TranslatorInterface;

/**
 * Locale-aware currency formatting via ext-intl.
 */
#[Internal]
final readonly class IntlCurrencyFormatter implements CurrencyFormatterInterface
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public function format(float $amount, string $currency, ?string $locale = null): string
    {
        $locale ??= $this->translator->locale;
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $result = $formatter->formatCurrency($amount, $currency);

        return $result !== false ? $result : (string) $amount;
    }
}
