<?php

declare(strict_types=1);

namespace Pulsar\Observability\Rum;

use Pulsar\Api\Api;

use function array_key_exists;
use function count;
use function is_string;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function strlen;
use function substr;

use const PHP_URL_PATH;

/**
 * Bounds the cardinality of the `url` label RUM metrics are recorded under.
 *
 * The value arrives in a browser-supplied JSON body, and every distinct value
 * opens a new time series in {@see \Pulsar\Observability\Metrics\MetricRegistry}.
 * Truncating it to 200 characters, as the collector originally did, bounds the
 * length of each label and nothing about how many there are: a caller sending
 * `/a`, `/b`, `/c`… mints a series per request, for as long as it keeps sending.
 * The registry is an in-process array, so that is unbounded memory growth in the
 * worker and an unbounded, attacker-authored metrics export for whatever scrapes
 * it.
 *
 * This clamps the axis. A reported URL is reduced to its path, checked against a
 * conservative shape, and admitted only while the distinct-value budget lasts;
 * past that every further value collapses to {@see RumUrlLabels::OVERFLOW_LABEL}.
 * Real traffic to a real site fits well inside the budget — a page count, not a
 * request count — and abusive traffic buys one extra series in total.
 *
 * The budget is per process, alongside the registry it protects.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class RumUrlLabels
{
    /** Distinct paths admitted before everything else collapses. */
    public const int DEFAULT_LIMIT = 200;

    /** Label every path past the budget is recorded under. */
    public const string OVERFLOW_LABEL = 'other';

    /** Label used when the client reported no usable path. */
    public const string UNKNOWN_LABEL = '/';

    /** Longest path retained; longer ones are rejected rather than truncated. */
    private const int MAX_LENGTH = 200;

    /**
     * Paths admitted so far, used as a set.
     *
     * @var array<string, true>
     */
    private array $admitted = [];

    public function __construct(
        private readonly int $limit = self::DEFAULT_LIMIT,
    ) {}

    /** How many distinct paths are currently admitted. */
    public function distinctCount(): int
    {
        return count($this->admitted);
    }

    /**
     * The label to record a metric under for a client-reported URL.
     *
     * @param mixed $reported The raw `url` field as it arrived in the payload
     */
    public function label(mixed $reported): string
    {
        $path = self::toPath($reported);

        if ($path === null) {
            return self::UNKNOWN_LABEL;
        }

        if (array_key_exists($path, $this->admitted)) {
            return $path;
        }

        if (count($this->admitted) >= $this->limit) {
            return self::OVERFLOW_LABEL;
        }

        $this->admitted[$path] = true;

        return $path;
    }

    /**
     * Reduce a reported URL to a path worth labelling with, or null.
     *
     * Query strings and fragments are dropped: they carry session tokens, search
     * terms and other request-scoped data that has no place in a metric label,
     * and they multiply cardinality per visitor. The character class is
     * deliberately narrow — a label ends up in an OpenMetrics exposition read by
     * a scraper, so anything that could break that line out of its quoting is
     * refused rather than escaped.
     */
    private static function toPath(mixed $reported): ?string
    {
        if (!is_string($reported) || $reported === '') {
            return null;
        }

        if (strlen($reported) > 2048) {
            return null;
        }

        /** @var string|false|null $path */
        $path = parse_url($reported, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return null;
        }

        // Percent-encoding would otherwise smuggle a newline or a quote past the
        // character class below and into the exposition format.
        $path = rawurldecode($path);

        if (strlen($path) > self::MAX_LENGTH) {
            return null;
        }

        if (preg_match('#\A/[A-Za-z0-9._~/-]*\z#', $path) !== 1) {
            return null;
        }

        return substr($path, 0, self::MAX_LENGTH);
    }
}
