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
final readonly class TabsBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'tabs';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tabs' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'content'],
                    ],
                ],
                'defaultActive' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['tabs'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<array{title: string, content: string}> $tabs */
        $tabs = $data['tabs'] ?? [];
        $defaultActive = (int) ($data['defaultActive'] ?? 0);

        $html = '<div class="tabs">';
        $html .= '<div role="tablist" class="tabs__list">';

        foreach ($tabs as $i => $tab) {
            if (!is_array($tab)) {
                continue;
            }

            $title = htmlspecialchars((string) ($tab['title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $selected = $i === $defaultActive ? 'true' : 'false';

            $html .= "<button role=\"tab\" id=\"tab-{$i}\" aria-controls=\"panel-{$i}\" aria-selected=\"{$selected}\" class=\"tabs__tab\">{$title}</button>";
        }

        $html .= '</div>';

        foreach ($tabs as $i => $tab) {
            if (!is_array($tab)) {
                continue;
            }

            $content = htmlspecialchars((string) ($tab['content'] ?? ''), ENT_QUOTES, 'UTF-8');
            $hidden = $i !== $defaultActive ? ' hidden' : '';

            $html .= "<div role=\"tabpanel\" id=\"panel-{$i}\" aria-labelledby=\"tab-{$i}\" class=\"tabs__panel\"{$hidden}>{$content}</div>";
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['tabs']) || !is_array($data['tabs'])) {
            $errors[] = 'tabs is required and must be an array';

            return $errors;
        }

        foreach ($data['tabs'] as $index => $tab) {
            if (!is_array($tab)) {
                $errors[] = "tabs[{$index}] must be an object";

                continue;
            }

            if (!isset($tab['title']) || !is_string($tab['title'])) {
                $errors[] = "tabs[{$index}].title is required and must be a string";
            }

            if (!isset($tab['content']) || !is_string($tab['content'])) {
                $errors[] = "tabs[{$index}].content is required and must be a string";
            }
        }

        if (isset($data['defaultActive'])) {
            if (!is_int($data['defaultActive']) || $data['defaultActive'] < 0) {
                $errors[] = 'defaultActive must be a non-negative integer';
            } elseif ($data['defaultActive'] >= count($data['tabs'])) {
                $errors[] = 'defaultActive must be less than the number of tabs';
            }
        }

        return $errors;
    }
}
