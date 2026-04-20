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
final readonly class CategoriesBlock implements BlockTypeInterface
{
    private const array VALID_DISPLAYS = ['list', 'dropdown'];

    #[Override]
    public function type(): string
    {
        return 'categories';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'categories' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'url' => ['type' => 'string', 'format' => 'uri'],
                            'count' => ['type' => 'integer', 'minimum' => 0],
                        ],
                        'required' => ['name', 'url'],
                    ],
                ],
                'display' => ['type' => 'string', 'enum' => self::VALID_DISPLAYS],
                'showCounts' => ['type' => 'boolean'],
            ],
            'required' => ['categories'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $categories */
        $categories = $data['categories'] ?? [];
        /** @var mixed $rawDisplay */
        $rawDisplay = $data['display'] ?? null;
        $display = is_string($rawDisplay) ? $rawDisplay : 'list';
        /** @var mixed $rawShowCounts */
        $rawShowCounts = $data['showCounts'] ?? null;
        $showCounts = is_bool($rawShowCounts) ? $rawShowCounts : true;

        if ($display === 'dropdown') {
            return $this->renderDropdown($categories, $showCounts);
        }

        return $this->renderList($categories, $showCounts);
    }

    /**
     * @param list<mixed> $categories
     */
    private function renderList(array $categories, bool $showCounts): string
    {
        $html = '<ul class="categories-block">';

        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }

            /** @var mixed $rawName */
            $rawName = $category['name'] ?? null;
            /** @var mixed $rawUrl */
            $rawUrl = $category['url'] ?? null;
            /** @var mixed $rawCount */
            $rawCount = $category['count'] ?? null;
            $name = htmlspecialchars(is_string($rawName) ? $rawName : '', ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars(is_string($rawUrl) ? $rawUrl : '#', ENT_QUOTES, 'UTF-8');
            $count = is_int($rawCount) ? $rawCount : 0;

            $countSuffix = $showCounts ? " <span class=\"categories-block__count\">($count)</span>" : '';
            $html .= "<li><a href=\"$url\">$name</a>$countSuffix</li>";
        }

        return $html . '</ul>';
    }

    /**
     * @param list<mixed> $categories
     */
    private function renderDropdown(array $categories, bool $showCounts): string
    {
        $html = '<div class="categories-block categories-block--dropdown">'
            . '<label for="categories-select" class="sr-only">Select category</label>'
            . '<select id="categories-select" onchange="if(this.value)window.location.href=this.value">'
            . '<option value="">Select category</option>';

        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }

            /** @var mixed $rawName */
            $rawName = $category['name'] ?? null;
            /** @var mixed $rawUrl */
            $rawUrl = $category['url'] ?? null;
            /** @var mixed $rawCount */
            $rawCount = $category['count'] ?? null;
            $name = htmlspecialchars(is_string($rawName) ? $rawName : '', ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars(is_string($rawUrl) ? $rawUrl : '#', ENT_QUOTES, 'UTF-8');
            $count = is_int($rawCount) ? $rawCount : 0;

            $label = $showCounts ? "$name ($count)" : $name;
            $html .= "<option value=\"$url\">$label</option>";
        }

        return $html . '</select></div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['categories']) || !is_array($data['categories'])) {
            $errors[] = 'categories is required and must be an array';

            return $errors;
        }

        foreach ($data['categories'] as $index => $category) {
            if (!is_array($category)) {
                $errors[] = "categories[$index] must be an object";

                continue;
            }

            if (!isset($category['name']) || !is_string($category['name'])) {
                $errors[] = "categories[$index].name is required and must be a string";
            }

            if (!isset($category['url']) || !is_string($category['url'])) {
                $errors[] = "categories[$index].url is required and must be a string";
            }

            if (isset($category['count']) && (!is_int($category['count']) || $category['count'] < 0)) {
                $errors[] = "categories[$index].count must be a non-negative integer";
            }
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
