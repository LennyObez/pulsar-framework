<?php

declare(strict_types=1);

namespace Pulsar\I18n\Format;

use NumberFormatter;
use Pulsar\Api\Internal;
use Pulsar\I18n\TranslatorInterface;

/**
 * Locale-aware number formatting via ext-intl.
 */
#[Internal]
final readonly class IntlNumberFormatter implements NumberFormatterInterface
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public function format(int|float $number, ?string $locale = null): string
    {
        $locale ??= $this->translator->getLocale();
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $result = $formatter->format($number);

        return $result !== false ? $result : (string) $number;
    }
}
