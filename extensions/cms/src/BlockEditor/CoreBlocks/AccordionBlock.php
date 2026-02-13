<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_array;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class AccordionBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'accordion';
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
                            'title' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'content'],
                    ],
                ],
                'allowMultiple' => ['type' => 'boolean'],
            ],
            'required' => ['items'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $items */
        $items = $data['items'] ?? [];
        $allowMultiple = ($data['allowMultiple'] ?? false) === true ? 'true' : 'false';

        $html = "<div class=\"accordion\" data-allow-multiple=\"$allowMultiple\">";

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $content = htmlspecialchars((string) ($item['content'] ?? ''), ENT_QUOTES, 'UTF-8');

            $html .= '<details class="accordion__item">';
            $html .= "<summary class=\"accordion__title\">$title</summary>";
            $html .= "<div class=\"accordion__content\">$content</div>";
            $html .= '</details>';
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
                $errors[] = "items[$index] must be an object";

                continue;
            }

            if (!isset($item['title']) || !is_string($item['title'])) {
                $errors[] = "items[$index].title is required and must be a string";
            }

            if (!isset($item['content']) || !is_string($item['content'])) {
                $errors[] = "items[$index].content is required and must be a string";
            }
        }

        return $errors;
    }
}
