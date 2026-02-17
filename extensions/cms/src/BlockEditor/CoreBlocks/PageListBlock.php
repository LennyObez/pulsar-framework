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
final readonly class PageListBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'page-list';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pages' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'children' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#'],
                            ],
                        ],
                        'required' => ['title', 'url'],
                    ],
                ],
                'showHierarchy' => ['type' => 'boolean'],
                'parentPageId' => ['type' => 'string'],
            ],
            'required' => ['pages'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $pages */
        $pages = $data['pages'] ?? [];
        $showHierarchy = is_bool($data['showHierarchy'] ?? null) ? $data['showHierarchy'] : true;

        $html = '<nav class="page-list-block" aria-label="Page list">';
        $html .= $this->renderPageList($pages, $showHierarchy);

        return $html . '</nav>';
    }

    /**
     * @param list<mixed> $pages
     */
    private function renderPageList(array $pages, bool $showHierarchy): string
    {
        $html = '<ul class="page-list-block__list">';

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $title = htmlspecialchars(is_string($page['title'] ?? null) ? $page['title'] : '', ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars(is_string($page['url'] ?? null) ? $page['url'] : '#', ENT_QUOTES, 'UTF-8');

            $html .= "<li class=\"page-list-block__item\"><a href=\"$url\">$title</a>";

            /** @var list<mixed> $children */
            $children = $page['children'] ?? [];

            if ($showHierarchy && is_array($children) && $children !== []) {
                $html .= $this->renderPageList($children, true);
            }

            $html .= '</li>';
        }

        return $html . '</ul>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['pages']) || !is_array($data['pages'])) {
            $errors[] = 'pages is required and must be an array';

            return $errors;
        }

        foreach ($data['pages'] as $index => $page) {
            if (!is_array($page)) {
                $errors[] = "pages[$index] must be an object";

                continue;
            }

            if (!isset($page['title']) || !is_string($page['title'])) {
                $errors[] = "pages[$index].title is required and must be a string";
            }

            if (!isset($page['url']) || !is_string($page['url'])) {
                $errors[] = "pages[$index].url is required and must be a string";
            }

            if (isset($page['children']) && !is_array($page['children'])) {
                $errors[] = "pages[$index].children must be an array";
            }
        }

        if (isset($data['showHierarchy']) && !is_bool($data['showHierarchy'])) {
            $errors[] = 'showHierarchy must be a boolean';
        }

        if (isset($data['parentPageId']) && !is_string($data['parentPageId'])) {
            $errors[] = 'parentPageId must be a string';
        }

        return $errors;
    }
}
