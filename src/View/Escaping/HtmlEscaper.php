<?php

declare(strict_types=1);

namespace Pulsar\View\Escaping;

use Pulsar\Api\Api;

/**
 * Default HTML entity escaper.
 *
 * Uses htmlspecialchars with ENT_QUOTES | ENT_SUBSTITUTE for UTF-8 output.
 * This is the default escaper for {{ $var }} expressions.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HtmlEscaper implements EscaperInterface
{
    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
