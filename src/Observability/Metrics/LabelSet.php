<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use Pulsar\Api\Api;

use function implode;
use function ksort;
use function str_replace;

/**
 * Immutable label key-value set for metric dimensions.
 *
 * Labels are sorted deterministically by key to produce a stable
 * map lookup key via {@see key()}.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LabelSet
{
    /** @var array<string, string> */
    private array $labels;

    /**
     * @param array<string, string> $labels
     */
    public function __construct(array $labels = [])
    {
        $sorted = $labels;
        ksort($sorted);
        $this->labels = $sorted;
    }

    /**
     * Deterministic string key for map lookups.
     *
     * Format: "key1=value1,key2=value2" with keys sorted alphabetically.
     *
     * Values are reversibly escaped so that a literal comma in a value (e.g. a
     * user-supplied `url` label) does not collide with the `,` pair separator
     * when the key is parsed back into labels (see OpenMetricsExporter). The
     * escape is a no-op for values without `%` or `,`, so the documented format
     * is byte-identical for the common case.
     */
    public function key(): string
    {
        if ($this->labels === []) {
            return '';
        }

        $parts = [];

        foreach ($this->labels as $k => $v) {
            $parts[] = $k . '=' . self::escapeValue($v);
        }

        return implode(',', $parts);
    }

    /**
     * Reverse of {@see escapeValue()}: decode an escaped value from a key string.
     */
    public static function unescapeValue(string $value): string
    {
        // Decode comma before percent so a literal "%2C" in the source value
        // (encoded as "%252C") is not mistaken for an escaped comma.
        return str_replace(['%2C', '%25'], [',', '%'], $value);
    }

    /**
     * Escape a label value for embedding in the comma-separated key string.
     *
     * Percent is escaped first so the escaping is fully reversible.
     */
    private static function escapeValue(string $value): string
    {
        return str_replace(['%', ','], ['%25', '%2C'], $value);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->labels;
    }

    public function isEmpty(): bool
    {
        return $this->labels === [];
    }
}
