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
    private const int MAX_CACHE_SIZE = 200;

    /** @var array<string, MessageFormatter> */
    private array $cache = [];

    /**
     * @param array<string, mixed> $parameters
     */
    public function format(string $pattern, array $parameters, string $locale): string
    {
        if ($parameters === []) {
            return $pattern;
        }

        $cacheKey = $locale . '|' . $pattern;

        if (isset($this->cache[$cacheKey])) {
            $formatter = $this->cache[$cacheKey];
        } else {
            $formatter = MessageFormatter::create($locale, $pattern);

            if ($formatter === null) {
                return $pattern;
            }

            // Evict oldest entries when cache is full
            if (\count($this->cache) >= self::MAX_CACHE_SIZE) {
                \array_shift($this->cache);
            }

            $this->cache[$cacheKey] = $formatter;
        }

        $result = $formatter->format($parameters);

        if ($result === false) {
            return $pattern;
        }

        return $result;
    }
}
