<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class RssBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'rss';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'feedUrl' => ['type' => 'string', 'format' => 'uri'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'url' => ['type' => 'string', 'format' => 'uri'],
                            'description' => ['type' => 'string'],
                            'date' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'url'],
                    ],
                ],
                'maxItems' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                'showDescription' => ['type' => 'boolean'],
                'showDate' => ['type' => 'boolean'],
            ],
            'required' => ['feedUrl', 'items'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<array{title?: string, url?: string, date?: string, description?: string}> $items */
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $showDescription = is_bool($data['showDescription'] ?? null) ? $data['showDescription'] : true;
        $showDate = is_bool($data['showDate'] ?? null) ? $data['showDate'] : true;
        $feedUrl = htmlspecialchars(is_string($data['feedUrl'] ?? null) ? $data['feedUrl'] : '', ENT_QUOTES, 'UTF-8');

        $html = "<div class=\"rss-block\" data-feed-url=\"$feedUrl\">";
        $html .= '<ul class="rss-block__list">';

        foreach ($items as $item) {
            $title = htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars($item['url'] ?? '#', ENT_QUOTES, 'UTF-8');

            $html .= '<li class="rss-block__item">';
            $html .= "<a href=\"$url\" rel=\"noopener noreferrer\" target=\"_blank\">$title</a>";

            $date = $item['date'] ?? '';
            if ($showDate && $date !== '') {
                $dateEsc = htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
                $html .= "<time class=\"rss-block__date\">$dateEsc</time>";
            }

            $desc = $item['description'] ?? '';
            if ($showDescription && $desc !== '') {
                $descEsc = htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');
                $html .= "<p class=\"rss-block__description\">$descEsc</p>";
            }

            $html .= '</li>';
        }

        return $html . '</ul></div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['feedUrl']) || !is_string($data['feedUrl'])) {
            $errors[] = 'feedUrl is required and must be a string';
        }

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

            if (!isset($item['url']) || !is_string($item['url'])) {
                $errors[] = "items[$index].url is required and must be a string";
            }
        }

        if (isset($data['maxItems']) && (!is_int($data['maxItems']) || $data['maxItems'] < 1 || $data['maxItems'] > 20)) {
            $errors[] = 'maxItems must be an integer between 1 and 20';
        }

        if (isset($data['showDescription']) && !is_bool($data['showDescription'])) {
            $errors[] = 'showDescription must be a boolean';
        }

        if (isset($data['showDate']) && !is_bool($data['showDate'])) {
            $errors[] = 'showDate must be a boolean';
        }

        return $errors;
    }
}
