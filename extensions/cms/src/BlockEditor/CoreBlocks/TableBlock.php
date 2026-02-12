<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_array;
use function is_bool;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class TableBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'table';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'headers' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'rows' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'hasHeaderRow' => ['type' => 'boolean'],
            ],
            'required' => ['headers', 'rows', 'hasHeaderRow'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<string> $headers */
        $headers = $data['headers'] ?? [];

        /** @var list<mixed> $rows */
        $rows = $data['rows'] ?? [];
        $hasHeaderRow = (bool) ($data['hasHeaderRow'] ?? true);

        $html = '<table>';

        if ($hasHeaderRow && $headers !== []) {
            $html .= '<thead><tr>';

            foreach ($headers as $header) {
                $html .= '<th>' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</th>';
            }

            $html .= '</tr></thead>';
        }

        $html .= '<tbody>';

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $html .= '<tr>';

            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['headers']) || !is_array($data['headers'])) {
            $errors[] = 'headers is required and must be an array';
        } else {
            foreach ($data['headers'] as $index => $header) {
                if (!is_string($header)) {
                    $errors[] = "headers[{$index}] must be a string";
                }
            }
        }

        if (!isset($data['rows']) || !is_array($data['rows'])) {
            $errors[] = 'rows is required and must be an array';
        } else {
            foreach ($data['rows'] as $rowIndex => $row) {
                if (!is_array($row)) {
                    $errors[] = "rows[{$rowIndex}] must be an array";

                    continue;
                }

                foreach ($row as $cellIndex => $cell) {
                    if (!is_string($cell)) {
                        $errors[] = "rows[{$rowIndex}][{$cellIndex}] must be a string";
                    }
                }
            }
        }

        if (!isset($data['hasHeaderRow']) || !is_bool($data['hasHeaderRow'])) {
            $errors[] = 'hasHeaderRow is required and must be a boolean';
        }

        return $errors;
    }
}
