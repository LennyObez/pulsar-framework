<?php

declare(strict_types=1);

namespace Pulsar\View\Escaping;

use Pulsar\Api\Api;

/**
 * Contract for context-aware output escaping.
 *
 * Implementations handle escaping for specific output contexts (HTML, URL,
 * attribute, JavaScript, CSS) to prevent injection attacks.
 */
#[Api(since: '1.0.0')]
interface EscaperInterface
{
    /**
     * Escape the given value for safe output in this context.
     *
     * @param string $value The raw value to escape
     *
     * @return string The escaped value
     */
    public function escape(string $value): string;
}
