<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Report;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function implode;

/**
 * The shapes a compliance report is emitted in.
 *
 * Text is what an operator reads at a terminal, Markdown is the document an
 * assessor is handed, and JSON is what a pipeline diffs between runs. All three
 * are rendered from the same findings, so none of them can say something the
 * others do not.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum ReportFormat: string
{
    case Text = 'text';
    case Json = 'json';
    case Markdown = 'markdown';

    /**
     * The accepted values, for the "unknown format" message.
     *
     * @return non-empty-string
     */
    #[NoDiscard]
    public static function accepted(): string
    {
        return implode('|', array_map(static fn(self $case): string => $case->value, self::cases()));
    }
}
