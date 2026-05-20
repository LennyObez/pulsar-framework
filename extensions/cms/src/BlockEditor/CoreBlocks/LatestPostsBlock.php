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
final readonly class LatestPostsBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'latest-posts';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'posts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'url' => ['type' => 'string', 'format' => 'uri'],
                            'excerpt' => ['type' => 'string'],
                            'thumbnail' => ['type' => 'string', 'format' => 'uri'],
                            'date' => ['type' => 'string'],
                            'author' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'url'],
                    ],
                ],
                'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                'showExcerpt' => ['type' => 'boolean'],
                'showThumbnail' => ['type' => 'boolean'],
                'showDate' => ['type' => 'boolean'],
                'showAuthor' => ['type' => 'boolean'],
                'category' => ['type' => 'string'],
            ],
            'required' => ['posts'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawPosts */
        $rawPosts = $data['posts'] ?? null;
        /** @var list<array{title?: string, url?: string, thumbnail?: string, date?: string, author?: string, excerpt?: string}> $posts */
        $posts = is_array($rawPosts) ? $rawPosts : [];
        /** @var mixed $rawShowExcerpt */
        $rawShowExcerpt = $data['showExcerpt'] ?? null;
        /** @var mixed $rawShowThumbnail */
        $rawShowThumbnail = $data['showThumbnail'] ?? null;
        /** @var mixed $rawShowDate */
        $rawShowDate = $data['showDate'] ?? null;
        /** @var mixed $rawShowAuthor */
        $rawShowAuthor = $data['showAuthor'] ?? null;
        $showExcerpt = is_bool($rawShowExcerpt) ? $rawShowExcerpt : true;
        $showThumbnail = is_bool($rawShowThumbnail) ? $rawShowThumbnail : false;
        $showDate = is_bool($rawShowDate) ? $rawShowDate : true;
        $showAuthor = is_bool($rawShowAuthor) ? $rawShowAuthor : false;

        $html = '<div class="latest-posts">';

        foreach ($posts as $post) {
            $title = htmlspecialchars($post['title'] ?? '', ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars($post['url'] ?? '#', ENT_QUOTES, 'UTF-8');

            $html .= '<article class="latest-posts__item">';

            $thumbnail = $post['thumbnail'] ?? '';
            if ($showThumbnail && $thumbnail !== '') {
                $thumb = htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8');
                $html .= "<img src=\"$thumb\" alt=\"\" class=\"latest-posts__thumbnail\" loading=\"lazy\">";
            }

            $html .= "<h3 class=\"latest-posts__title\"><a href=\"$url\">$title</a></h3>";

            $dateValue = $post['date'] ?? '';
            if ($showDate && $dateValue !== '') {
                $date = htmlspecialchars($dateValue, ENT_QUOTES, 'UTF-8');
                $html .= "<time class=\"latest-posts__date\">$date</time>";
            }

            $authorValue = $post['author'] ?? '';
            if ($showAuthor && $authorValue !== '') {
                $author = htmlspecialchars($authorValue, ENT_QUOTES, 'UTF-8');
                $html .= "<span class=\"latest-posts__author\">$author</span>";
            }

            $excerptValue = $post['excerpt'] ?? '';
            if ($showExcerpt && $excerptValue !== '') {
                $excerpt = htmlspecialchars($excerptValue, ENT_QUOTES, 'UTF-8');
                $html .= "<p class=\"latest-posts__excerpt\">$excerpt</p>";
            }

            $html .= '</article>';
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['posts']) || !is_array($data['posts'])) {
            $errors[] = 'posts is required and must be an array';

            return $errors;
        }

        foreach ($data['posts'] as $index => $post) {
            if (!is_array($post)) {
                $errors[] = "posts[$index] must be an object";

                continue;
            }

            if (!isset($post['title']) || !is_string($post['title'])) {
                $errors[] = "posts[$index].title is required and must be a string";
            }

            if (!isset($post['url']) || !is_string($post['url'])) {
                $errors[] = "posts[$index].url is required and must be a string";
            }
        }

        if (isset($data['count']) && (!is_int($data['count']) || $data['count'] < 1 || $data['count'] > 50)) {
            $errors[] = 'count must be an integer between 1 and 50';
        }

        if (isset($data['showExcerpt']) && !is_bool($data['showExcerpt'])) {
            $errors[] = 'showExcerpt must be a boolean';
        }

        if (isset($data['showThumbnail']) && !is_bool($data['showThumbnail'])) {
            $errors[] = 'showThumbnail must be a boolean';
        }

        if (isset($data['showDate']) && !is_bool($data['showDate'])) {
            $errors[] = 'showDate must be a boolean';
        }

        if (isset($data['showAuthor']) && !is_bool($data['showAuthor'])) {
            $errors[] = 'showAuthor must be a boolean';
        }

        if (isset($data['category']) && !is_string($data['category'])) {
            $errors[] = 'category must be a string';
        }

        return $errors;
    }
}
