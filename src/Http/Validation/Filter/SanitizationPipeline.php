<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Filter;

use NoDiscard;
use Pulsar\Api\Api;

use function array_values;

/**
 * Runs a sequence of filters over input data fields.
 *
 * Preserves immutable originals so downstream code can compare
 * sanitized vs. original values when needed.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SanitizationPipeline
{
    /** @var list<FilterInterface> */
    private array $filters;

    public function __construct(FilterInterface ...$filters)
    {
        $this->filters = array_values($filters);
    }

    /**
     * Run all filters over the given data.
     *
     * @param array<string, mixed> $data Input data keyed by field name
     */
    #[NoDiscard]
    public function sanitize(array $data): SanitizationResult
    {
        $originals = $data;
        $sanitized = $data;

        foreach ($sanitized as $field => $value) {
            foreach ($this->filters as $filter) {
                $value = $filter->apply($value);
            }

            $sanitized[$field] = $value;
        }

        return new SanitizationResult($sanitized, $originals);
    }
}
