<?php

declare(strict_types=1);

namespace Pulsar\Live\Admin;

use BackedEnum;
use Pulsar\Api\Api;

use function is_string;

/**
 * Dropdown select filter for admin resource lists.
 *
 *   SelectFilter::make('status')->options(Status::cases())
 *   SelectFilter::make('role')->options(['admin', 'user', 'editor'])
 * @api
 */
#[Api(since: '1.0.0')]
final class SelectFilter extends Filter
{
    /** @var list<string> */
    private array $options = [];

    public static function make(string $field, ?string $label = null): self
    {
        return new self(
            field: $field,
            label: $label ?? ucfirst(str_replace('_', ' ', $field)),
            type: 'select',
        );
    }

    /**
     * @param list<string>|list<BackedEnum> $options
     */
    public function options(array $options): self
    {
        $this->options = array_map(
            static fn(string|BackedEnum $opt): string => $opt instanceof BackedEnum ? (string) $opt->value : $opt,
            $options,
        );

        return $this;
    }

    /** @return list<string> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @inheritDoc */
    public function apply(array $query, mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $query[$this->field] = $value;
        }

        return $query;
    }
}
