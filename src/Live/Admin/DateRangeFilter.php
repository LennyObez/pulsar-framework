<?php

declare(strict_types=1);

namespace Pulsar\Live\Admin;

use Pulsar\Api\Api;

use function is_array;
use function is_string;

/**
 * Date range filter for admin resource lists.
 *
 *   DateRangeFilter::make('created_at')
 * @api
 */
#[Api(since: '1.0.0')]
final class DateRangeFilter extends Filter
{
    public static function make(string $field, ?string $label = null): self
    {
        return new self(
            field: $field,
            label: $label ?? ucfirst(str_replace('_', ' ', $field)),
            type: 'date_range',
        );
    }

    /** @inheritDoc */
    public function apply(array $query, mixed $value): array
    {
        if (!is_array($value)) {
            return $query;
        }

        if (isset($value['from']) && is_string($value['from']) && $value['from'] !== '') {
            $query[$this->field . '_from'] = $value['from'];
        }

        if (isset($value['to']) && is_string($value['to']) && $value['to'] !== '') {
            $query[$this->field . '_to'] = $value['to'];
        }

        return $query;
    }
}
