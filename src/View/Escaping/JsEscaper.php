<?php

declare(strict_types=1);

namespace Pulsar\View\Escaping;

use Pulsar\Api\Api;

use function json_encode;

use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * JavaScript context escaper.
 *
 * JSON-encodes and escapes values for safe use inside inline <script> blocks.
 * Encodes HTML-significant characters to prevent breaking out of script context.
 */
#[Api(since: '1.0.0')]
final readonly class JsEscaper implements EscaperInterface
{
    public function escape(string $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        // Strip the surrounding quotes added by json_encode for strings
        return substr($encoded, 1, -1);
    }
}
