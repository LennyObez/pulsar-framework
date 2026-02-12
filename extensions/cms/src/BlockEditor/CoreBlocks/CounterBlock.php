<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function count;
use function htmlspecialchars;
use function is_array;
use function is_int;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class CounterBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'counter';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                        ],
                        'required' => ['value', 'label'],
                    ],
                ],
                'columns' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['items'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $items */
        $items = $data['items'] ?? [];
        $columns = $data['columns'] ?? null;

        if (!is_int($columns) || $columns < 1) {
            $columns = count($items);
        }

        if ($columns < 1) {
            $columns = 1;
        }

        $html = "<div class=\"counters\" style=\"display:grid;grid-template-columns:repeat({$columns},1fr);gap:1rem\">";

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $value = htmlspecialchars((string) ($item['value'] ?? ''), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');

            $html .= "<div class=\"counter\"><span class=\"counter__value\">{$value}</span><span class=\"counter__label\">{$label}</span></div>";
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['items']) || !is_array($data['items'])) {
            $errors[] = 'items is required and must be an array';

            return $errors;
        }

        foreach ($data['items'] as $index => $item) {
            if (!is_array($item)) {
                $errors[] = "items[{$index}] must be an object";

                continue;
            }

            if (!isset($item['value']) || !is_string($item['value'])) {
                $errors[] = "items[{$index}].value is required and must be a string";
            }

            if (!isset($item['label']) || !is_string($item['label'])) {
                $errors[] = "items[{$index}].label is required and must be a string";
            }
        }

        if (isset($data['columns']) && (!is_int($data['columns']) || $data['columns'] < 1)) {
            $errors[] = 'columns must be a positive integer';
        }

        return $errors;
    }
}
