<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockRenderer;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function count;
use function is_array;
use function is_string;

#[Internal]
final readonly class ColumnsBlock implements BlockTypeInterface
{
    public function __construct(
        private BlockRenderer $renderer,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'columns';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'columns' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'blocks' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'blockType' => ['type' => 'string'],
                                        'data' => ['type' => 'object'],
                                    ],
                                    'required' => ['blockType', 'data'],
                                ],
                            ],
                        ],
                        'required' => ['blocks'],
                    ],
                ],
            ],
            'required' => ['columns'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $columns */
        $columns = $data['columns'] ?? [];
        $columnCount = count($columns);

        if ($columnCount === 0) {
            return '<div class="columns"></div>';
        }

        $html = "<div class=\"columns\" style=\"display:grid;grid-template-columns:repeat({$columnCount},1fr);gap:1rem\">";

        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }

            $html .= '<div class="column">';

            $blocks = $column['blocks'] ?? [];

            if (is_array($blocks)) {
                $html .= $this->renderer->renderRawBlocks($blocks);
            }

            $html .= '</div>';
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['columns']) || !is_array($data['columns'])) {
            $errors[] = 'columns is required and must be an array';

            return $errors;
        }

        foreach ($data['columns'] as $colIndex => $column) {
            if (!is_array($column)) {
                $errors[] = "columns[{$colIndex}] must be an object";

                continue;
            }

            if (!isset($column['blocks']) || !is_array($column['blocks'])) {
                $errors[] = "columns[{$colIndex}].blocks is required and must be an array";

                continue;
            }

            foreach ($column['blocks'] as $blockIndex => $block) {
                if (!is_array($block)) {
                    $errors[] = "columns[{$colIndex}].blocks[{$blockIndex}] must be an object";

                    continue;
                }

                if (!isset($block['blockType']) || !is_string($block['blockType'])) {
                    $errors[] = "columns[{$colIndex}].blocks[{$blockIndex}].blockType is required and must be a string";
                }

                if (!isset($block['data']) || !is_array($block['data'])) {
                    $errors[] = "columns[{$colIndex}].blocks[{$blockIndex}].data is required and must be an object";
                }
            }
        }

        return $errors;
    }
}
