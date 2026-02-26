<?php

declare(strict_types=1);

namespace Pulsar\I18n\Format;

use DateTimeInterface;
use IntlDateFormatter as PhpIntlDateFormatter;
use Pulsar\Api\Internal;
use Pulsar\I18n\TranslatorInterface;

/**
 * Locale-aware date/time formatting via ext-intl.
 */
#[Internal]
final readonly class IntlDateFormatter implements DateFormatterInterface
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public function format(DateTimeInterface $date, ?string $locale = null, int $dateType = PhpIntlDateFormatter::MEDIUM, int $timeType = PhpIntlDateFormatter::SHORT): string
    {
        $locale ??= $this->translator->locale;
        $formatter = new PhpIntlDateFormatter($locale, $dateType, $timeType);
        $result = $formatter->format($date);

        return $result !== false ? $result : $date->format('Y-m-d H:i:s');
    }
}
