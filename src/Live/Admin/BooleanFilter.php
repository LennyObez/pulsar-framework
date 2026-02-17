<?php

declare(strict_types=1);

namespace Pulsar\Live\Admin;

use Pulsar\Api\Api;

/**
 * Boolean (yes/no/all) filter for admin resource lists.
 *
 *   BooleanFilter::make('is_active')
 */
#[Api(since: '1.0.0')]
final class BooleanFilter extends Filter
{
    public static function make(string $field, ?string $label = null): self
    {
        return new self(
            field: $field,
            label: $label ?? ucfirst(str_replace('_', ' ', $field)),
            type: 'boolean',
        );
    }

    /** @inheritDoc */
    public function apply(array $query, mixed $value): array
    {
        if ($value === true || $value === 'true' || $value === '1') {
            $query[$this->field] = true;
        } elseif ($value === false || $value === 'false' || $value === '0') {
            $query[$this->field] = false;
        }

        return $query;
    }
}
