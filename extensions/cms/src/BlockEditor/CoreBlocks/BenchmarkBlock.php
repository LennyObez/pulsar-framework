<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_array;
use function is_numeric;
use function is_string;
use function number_format;

use const ENT_QUOTES;

#[Internal]
final readonly class BenchmarkBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'benchmark';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'metrics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'unit' => ['type' => 'string'],
                            'entries' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'label' => ['type' => 'string'],
                                        'value' => ['type' => 'number'],
                                        'highlight' => ['type' => 'boolean'],
                                    ],
                                    'required' => ['label', 'value'],
                                ],
                            ],
                        ],
                        'required' => ['name', 'entries'],
                    ],
                ],
                'source' => ['type' => 'string'],
                'sourceUrl' => ['type' => 'string', 'format' => 'uri'],
            ],
            'required' => ['title', 'metrics'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawTitle */
        $rawTitle = $data['title'] ?? null;
        $title = htmlspecialchars(is_string($rawTitle) ? $rawTitle : '', ENT_QUOTES, 'UTF-8');
        /** @var mixed $description */
        $description = $data['description'] ?? null;

        /** @var list<mixed> $metrics */
        $metrics = $data['metrics'] ?? [];

        $html = '<div class="benchmark-block">';
        $html .= "<h3 class=\"benchmark-block__title\">$title</h3>";

        if (is_string($description) && $description !== '') {
            $escapedDesc = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
            $html .= "<p class=\"benchmark-block__description\">$escapedDesc</p>";
        }

        foreach ($metrics as $metric) {
            if (!is_array($metric)) {
                continue;
            }

            /** @var array<string, mixed> $metric */
            $html .= $this->renderMetric($metric);
        }

        /** @var mixed $source */
        $source = $data['source'] ?? null;
        /** @var mixed $sourceUrl */
        $sourceUrl = $data['sourceUrl'] ?? null;

        if (is_string($source) && $source !== '') {
            $escapedSource = htmlspecialchars($source, ENT_QUOTES, 'UTF-8');

            if (is_string($sourceUrl) && $sourceUrl !== '') {
                $escapedUrl = htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8');
                $html .= "<p class=\"benchmark-block__source\">Source: <a href=\"$escapedUrl\" rel=\"noopener noreferrer\">$escapedSource</a></p>";
            } else {
                $html .= "<p class=\"benchmark-block__source\">Source: $escapedSource</p>";
            }
        }

        return $html . '</div>';
    }

    /**
     * @param array<string, mixed> $metric
     */
    private function renderMetric(array $metric): string
    {
        /** @var mixed $rawName */
        $rawName = $metric['name'] ?? null;
        /** @var mixed $rawUnit */
        $rawUnit = $metric['unit'] ?? null;
        $name = htmlspecialchars(is_string($rawName) ? $rawName : '', ENT_QUOTES, 'UTF-8');
        $unit = htmlspecialchars(is_string($rawUnit) ? $rawUnit : '', ENT_QUOTES, 'UTF-8');

        /** @var list<mixed> $entries */
        $entries = $metric['entries'] ?? [];

        // Find max value for bar widths
        $maxValue = 0.0;

        foreach ($entries as $entry) {
            if (is_array($entry) && is_numeric($entry['value'] ?? null)) {
                $val = (float) $entry['value'];

                if ($val > $maxValue) {
                    $maxValue = $val;
                }
            }
        }

        $html = '<table class="benchmark-block__table">';
        $html .= "<caption>$name" . ($unit !== '' ? " ($unit)" : '') . '</caption>';
        $html .= '<thead><tr><th>Framework</th><th>Result</th><th>Chart</th></tr></thead><tbody>';

        /** @var mixed $entry */
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /** @var mixed $rawLabel */
            $rawLabel = $entry['label'] ?? null;
            $label = htmlspecialchars(is_string($rawLabel) ? $rawLabel : '', ENT_QUOTES, 'UTF-8');
            /** @var mixed $rawValue */
            $rawValue = $entry['value'] ?? null;
            $value = is_numeric($rawValue) ? (float) $rawValue : 0.0;
            $highlight = ($entry['highlight'] ?? false) === true;

            $formatted = number_format($value, 2);
            $percentage = $maxValue > 0 ? ($value / $maxValue) * 100 : 0;
            $highlightClass = $highlight ? ' benchmark-block__row--highlight' : '';

            $html .= "<tr class=\"benchmark-block__row$highlightClass\">";
            $html .= "<td>$label</td>";
            $html .= "<td>$formatted $unit</td>";
            $html .= '<td><div class="benchmark-block__bar" style="width:' . number_format($percentage, 1) . '%"></div></td>';
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['title']) || !is_string($data['title'])) {
            $errors[] = 'title is required and must be a string';
        }

        if (!isset($data['metrics']) || !is_array($data['metrics'])) {
            $errors[] = 'metrics is required and must be an array';

            return $errors;
        }

        foreach ($data['metrics'] as $mi => $metric) {
            if (!is_array($metric)) {
                $errors[] = "metrics[$mi] must be an object";

                continue;
            }

            if (!isset($metric['name']) || !is_string($metric['name'])) {
                $errors[] = "metrics[$mi].name is required and must be a string";
            }

            if (!isset($metric['entries']) || !is_array($metric['entries'])) {
                $errors[] = "metrics[$mi].entries is required and must be an array";

                continue;
            }

            /** @var mixed $entry */
            foreach ($metric['entries'] as $ei => $entry) {
                if (!is_array($entry)) {
                    $errors[] = "metrics[$mi].entries[$ei] must be an object";

                    continue;
                }

                if (!isset($entry['label']) || !is_string($entry['label'])) {
                    $errors[] = "metrics[$mi].entries[$ei].label is required and must be a string";
                }

                if (!isset($entry['value']) || !is_numeric($entry['value'])) {
                    $errors[] = "metrics[$mi].entries[$ei].value is required and must be a number";
                }
            }
        }

        if (isset($data['description']) && !is_string($data['description'])) {
            $errors[] = 'description must be a string';
        }

        if (isset($data['source']) && !is_string($data['source'])) {
            $errors[] = 'source must be a string';
        }

        if (isset($data['sourceUrl']) && !is_string($data['sourceUrl'])) {
            $errors[] = 'sourceUrl must be a string';
        }

        return $errors;
    }
}
