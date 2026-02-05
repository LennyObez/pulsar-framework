<?php

declare(strict_types=1);

namespace Pulsar\I18n\Format;

use Pulsar\Api\Internal;

/**
 * Internal contract for ICU message formatting.
 */
#[Internal]
interface MessageFormatterInterface
{
    /**
     * Format a message pattern with parameters.
     *
     * @param string $pattern ICU message pattern or simple template
     * @param array<string, mixed> $parameters Substitution values
     * @param string $locale Target locale for formatting rules
     */
    public function format(string $pattern, array $parameters, string $locale): string;
}
