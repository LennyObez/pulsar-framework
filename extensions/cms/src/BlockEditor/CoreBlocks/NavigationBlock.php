<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class NavigationBlock implements BlockTypeInterface
{
    private const array VALID_ORIENTATIONS = ['horizontal', 'vertical'];

    #[Override]
    public function type(): string
    {
        return 'navigation';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'menuId' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'active' => ['type' => 'boolean'],
                            'children' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'label' => ['type' => 'string'],
                                        'url' => ['type' => 'string'],
                                        'active' => ['type' => 'boolean'],
                                    ],
                                    'required' => ['label', 'url'],
                                ],
                            ],
                        ],
                        'required' => ['label', 'url'],
                    ],
                ],
                'orientation' => ['type' => 'string', 'enum' => self::VALID_ORIENTATIONS],
                'ariaLabel' => ['type' => 'string'],
            ],
            'required' => ['items'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $items */
        $items = $data['items'] ?? [];
        $orientation = is_string($data['orientation'] ?? null) ? $data['orientation'] : 'horizontal';
        $ariaLabel = htmlspecialchars(
            is_string($data['ariaLabel'] ?? null) ? $data['ariaLabel'] : 'Navigation',
            ENT_QUOTES,
            'UTF-8',
        );

        $html = "<nav class=\"navigation-block navigation-block--$orientation\" aria-label=\"$ariaLabel\">";
        $html .= '<ul class="navigation-block__list">';

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $html .= $this->renderItem($item);
        }

        return $html . '</ul></nav>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderItem(array $item): string
    {
        $label = htmlspecialchars(is_string($item['label'] ?? null) ? $item['label'] : '', ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars(is_string($item['url'] ?? null) ? $item['url'] : '#', ENT_QUOTES, 'UTF-8');
        $active = ($item['active'] ?? false) === true;
        $children = $item['children'] ?? [];

        $activeClass = $active ? ' navigation-block__item--active' : '';
        $ariaCurrent = $active ? ' aria-current="page"' : '';

        $html = "<li class=\"navigation-block__item$activeClass\">";
        $html .= "<a href=\"$url\"$ariaCurrent>$label</a>";

        if (is_array($children) && $children !== []) {
            $html .= '<ul class="navigation-block__submenu">';

            foreach ($children as $child) {
                if (!is_array($child)) {
                    continue;
                }

                /** @var array<string, mixed> $child */
                $html .= $this->renderItem($child);
            }

            $html .= '</ul>';
        }

        return $html . '</li>';
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
                $errors[] = "items[$index] must be an object";

                continue;
            }

            if (!isset($item['label']) || !is_string($item['label'])) {
                $errors[] = "items[$index].label is required and must be a string";
            }

            if (!isset($item['url']) || !is_string($item['url'])) {
                $errors[] = "items[$index].url is required and must be a string";
            }

            if (isset($item['children']) && !is_array($item['children'])) {
                $errors[] = "items[$index].children must be an array";
            }
        }

        if (isset($data['orientation']) && !in_array($data['orientation'], self::VALID_ORIENTATIONS, true)) {
            $errors[] = 'orientation must be one of: horizontal, vertical';
        }

        if (isset($data['ariaLabel']) && !is_string($data['ariaLabel'])) {
            $errors[] = 'ariaLabel must be a string';
        }

        return $errors;
    }
}
