<?php

declare(strict_types=1);

namespace Pulsar\I18n\Format;

use MessageFormatter;
use Pulsar\Api\Internal;

/**
 * ICU MessageFormat implementation using ext-intl.
 *
 * Wraps PHP's MessageFormatter for full ICU pattern support
 * including plurals, select, and nested formatting.
 */
#[Internal]
final class IcuMessageFormatter implements MessageFormatterInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function format(string $pattern, array $parameters, string $locale): string
    {
        if ($parameters === []) {
            return $pattern;
        }

        $formatter = MessageFormatter::create($locale, $pattern);

        if ($formatter === null) {
            return $pattern;
        }

        $result = $formatter->format($parameters);

        if ($result === false) {
            return $pattern;
        }

        return $result;
    }
}
