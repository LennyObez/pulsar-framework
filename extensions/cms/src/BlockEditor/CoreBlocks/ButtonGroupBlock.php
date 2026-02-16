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
final readonly class ButtonGroupBlock implements BlockTypeInterface
{
    private const array VALID_ALIGNMENTS = ['left', 'center', 'right'];
    private const array VALID_LAYOUTS = ['horizontal', 'vertical'];

    #[Override]
    public function type(): string
    {
        return 'button-group';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'buttons' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                        ],
                        'required' => ['text', 'url'],
                    ],
                ],
                'alignment' => ['type' => 'string'],
                'layout' => ['type' => 'string'],
            ],
            'required' => ['buttons'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $buttons */
        $buttons = $data['buttons'] ?? [];

        $alignment = $data['alignment'] ?? null;

        if (!is_string($alignment) || !in_array($alignment, self::VALID_ALIGNMENTS, true)) {
            $alignment = 'center';
        }

        $layout = $data['layout'] ?? null;

        if (!is_string($layout) || !in_array($layout, self::VALID_LAYOUTS, true)) {
            $layout = 'horizontal';
        }

        $html = "<div class=\"button-group button-group--$alignment button-group--$layout\">";

        foreach ($buttons as $button) {
            if (!is_array($button)) {
                continue;
            }

            $text = htmlspecialchars(is_string($button['text'] ?? null) ? $button['text'] : '', ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars(is_string($button['url'] ?? null) ? $button['url'] : '', ENT_QUOTES, 'UTF-8');

            $html .= "<a href=\"$url\" class=\"button-group__button\">$text</a>";
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['buttons']) || !is_array($data['buttons'])) {
            $errors[] = 'buttons is required and must be an array';

            return $errors;
        }

        foreach ($data['buttons'] as $index => $button) {
            if (!is_array($button)) {
                $errors[] = "buttons[$index] must be an object";

                continue;
            }

            if (!isset($button['text']) || !is_string($button['text'])) {
                $errors[] = "buttons[$index].text is required and must be a string";
            }

            if (!isset($button['url']) || !is_string($button['url'])) {
                $errors[] = "buttons[$index].url is required and must be a string";
            }
        }

        if (isset($data['alignment'])) {
            if (!is_string($data['alignment'])) {
                $errors[] = 'alignment must be a string';
            } elseif (!in_array($data['alignment'], self::VALID_ALIGNMENTS, true)) {
                $errors[] = 'alignment must be one of: left, center, right';
            }
        }

        if (isset($data['layout'])) {
            if (!is_string($data['layout'])) {
                $errors[] = 'layout must be a string';
            } elseif (!in_array($data['layout'], self::VALID_LAYOUTS, true)) {
                $errors[] = 'layout must be one of: horizontal, vertical';
            }
        }

        return $errors;
    }
}
