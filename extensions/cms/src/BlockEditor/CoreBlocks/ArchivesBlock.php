<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class ArchivesBlock implements BlockTypeInterface
{
    private const array VALID_GROUPS = ['monthly', 'yearly'];
    private const array VALID_DISPLAYS = ['list', 'dropdown'];

    #[Override]
    public function type(): string
    {
        return 'archives';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'archives' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'url' => ['type' => 'string', 'format' => 'uri'],
                            'count' => ['type' => 'integer', 'minimum' => 0],
                        ],
                        'required' => ['label', 'url'],
                    ],
                ],
                'groupBy' => ['type' => 'string', 'enum' => self::VALID_GROUPS],
                'display' => ['type' => 'string', 'enum' => self::VALID_DISPLAYS],
                'showCounts' => ['type' => 'boolean'],
            ],
            'required' => ['archives'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $archives */
        $archives = $data['archives'] ?? [];
        /** @var mixed $rawDisplay */
        $rawDisplay = $data['display'] ?? null;
        $display = is_string($rawDisplay) ? $rawDisplay : 'list';
        /** @var mixed $rawShowCounts */
        $rawShowCounts = $data['showCounts'] ?? null;
        $showCounts = is_bool($rawShowCounts) ? $rawShowCounts : true;

        if ($display === 'dropdown') {
            return $this->renderDropdown($archives, $showCounts);
        }

        return $this->renderList($archives, $showCounts);
    }

    /**
     * @param list<mixed> $archives
     */
    private function renderList(array $archives, bool $showCounts): string
    {
        $html = '<ul class="archives-block">';

        foreach ($archives as $archive) {
            if (!is_array($archive)) {
                continue;
            }

            /** @var mixed $rawLabel */
            $rawLabel = $archive['label'] ?? null;
            /** @var mixed $rawUrl */
            $rawUrl = $archive['url'] ?? null;
            /** @var mixed $rawCount */
            $rawCount = $archive['count'] ?? null;
            $label = htmlspecialchars(is_string($rawLabel) ? $rawLabel : '', ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars(is_string($rawUrl) ? $rawUrl : '#', ENT_QUOTES, 'UTF-8');
            $count = is_int($rawCount) ? $rawCount : 0;

            $countSuffix = $showCounts ? " <span class=\"archives-block__count\">($count)</span>" : '';
            $html .= "<li><a href=\"$url\">$label</a>$countSuffix</li>";
        }

        return $html . '</ul>';
    }

    /**
     * @param list<mixed> $archives
     */
    private function renderDropdown(array $archives, bool $showCounts): string
    {
        $html = '<div class="archives-block archives-block--dropdown">'
            . '<label for="archives-select" class="sr-only">Select archive</label>'
            . '<select id="archives-select" onchange="if(this.value)window.location.href=this.value">'
            . '<option value="">Select archive</option>';

        foreach ($archives as $archive) {
            if (!is_array($archive)) {
                continue;
            }

            /** @var mixed $rawLabel */
            $rawLabel = $archive['label'] ?? null;
            /** @var mixed $rawUrl */
            $rawUrl = $archive['url'] ?? null;
            /** @var mixed $rawCount */
            $rawCount = $archive['count'] ?? null;
            $label = htmlspecialchars(is_string($rawLabel) ? $rawLabel : '', ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars(is_string($rawUrl) ? $rawUrl : '#', ENT_QUOTES, 'UTF-8');
            $count = is_int($rawCount) ? $rawCount : 0;

            $text = $showCounts ? "$label ($count)" : $label;
            $html .= "<option value=\"$url\">$text</option>";
        }

        return $html . '</select></div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['archives']) || !is_array($data['archives'])) {
            $errors[] = 'archives is required and must be an array';

            return $errors;
        }

        foreach ($data['archives'] as $index => $archive) {
            if (!is_array($archive)) {
                $errors[] = "archives[$index] must be an object";

                continue;
            }

            if (!isset($archive['label']) || !is_string($archive['label'])) {
                $errors[] = "archives[$index].label is required and must be a string";
            }

            if (!isset($archive['url']) || !is_string($archive['url'])) {
                $errors[] = "archives[$index].url is required and must be a string";
            }

            if (isset($archive['count']) && (!is_int($archive['count']) || $archive['count'] < 0)) {
                $errors[] = "archives[$index].count must be a non-negative integer";
            }
        }

        if (isset($data['groupBy']) && !in_array($data['groupBy'], self::VALID_GROUPS, true)) {
            $errors[] = 'groupBy must be one of: monthly, yearly';
        }

        if (isset($data['display']) && !in_array($data['display'], self::VALID_DISPLAYS, true)) {
            $errors[] = 'display must be one of: list, dropdown';
        }

        if (isset($data['showCounts']) && !is_bool($data['showCounts'])) {
            $errors[] = 'showCounts must be a boolean';
        }

        return $errors;
    }
}
