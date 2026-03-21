<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use Pulsar\Api\Api;

use function implode;
use function ksort;

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
     */
    public function key(): string
    {
        if ($this->labels === []) {
            return '';
        }

        $parts = [];

        foreach ($this->labels as $k => $v) {
            $parts[] = $k . '=' . $v;
        }

        return implode(',', $parts);
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
