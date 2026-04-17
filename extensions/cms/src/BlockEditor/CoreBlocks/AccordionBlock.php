<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_array;
use function is_string;
use function json_encode;

use const ENT_QUOTES;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

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
        $anchor = isset($data['anchor']) && is_string($data['anchor']) ? ' id="' . htmlspecialchars($data['anchor'], ENT_QUOTES, 'UTF-8') . '"' : '';
        $className = isset($data['className']) && is_string($data['className']) ? ' ' . htmlspecialchars($data['className'], ENT_QUOTES, 'UTF-8') : '';

        $html = "<div class=\"accordion$className\" data-allow-multiple=\"$allowMultiple\"$anchor>";

        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }

            /** @var mixed $rawTitle */
            $rawTitle = $item['title'] ?? null;
            /** @var mixed $rawContent */
            $rawContent = $item['content'] ?? null;
            $title = htmlspecialchars(is_string($rawTitle) ? $rawTitle : '', ENT_QUOTES, 'UTF-8');
            $content = htmlspecialchars(is_string($rawContent) ? $rawContent : '', ENT_QUOTES, 'UTF-8');
            $headingId = "accordion-heading-$i";
            $panelId = "accordion-panel-$i";

            $html .= '<details class="accordion__item">';
            $html .= "<summary class=\"accordion__title\" id=\"$headingId\" aria-controls=\"$panelId\">$title</summary>";
            $html .= "<div class=\"accordion__content\" id=\"$panelId\" role=\"region\" aria-labelledby=\"$headingId\">$content</div>";
            $html .= '</details>';
        }

        // FAQ structured data for SEO
        if ($items !== []) {
            $html .= '<script type="application/ld+json">';
            $faqItems = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                /** @var mixed $rawQText */
                $rawQText = $item['title'] ?? null;
                /** @var mixed $rawAText */
                $rawAText = $item['content'] ?? null;
                $qText = is_string($rawQText) ? $rawQText : '';
                $aText = is_string($rawAText) ? $rawAText : '';

                $faqItems[] = [
                    '@type' => 'Question',
                    'name' => $qText,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $aText,
                    ],
                ];
            }

            $html .= json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => $faqItems,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $html .= '</script>';
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
