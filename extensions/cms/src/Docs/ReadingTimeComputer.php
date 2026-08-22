<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Docs;

use Pulsar\Api\Api;

use function ceil;
use function max;
use function str_word_count;
use function strip_tags;

/**
 * Computes estimated reading time for documentation content.
 *
 * @psalm-api Resolved by content controllers from the DI container;
 *            not new'd by name.
 * @api
 */
#[Api(since: '1.0.0')]
final class ReadingTimeComputer
{
    private const int WORDS_PER_MINUTE = 200;

    /**
     * Compute the estimated reading time in minutes for the given text.
     *
     * HTML tags are stripped before counting words. The result is always
     * at least 1 minute.
     */
    public static function compute(string $text): int
    {
        $wordCount = str_word_count(strip_tags($text));

        return max(1, (int) ceil($wordCount / self::WORDS_PER_MINUTE));
    }
}
