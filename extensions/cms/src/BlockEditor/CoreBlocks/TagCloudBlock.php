<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;

use const ENT_QUOTES;

#[Internal]
final readonly class TagCloudBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'tag-cloud';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'url' => ['type' => 'string', 'format' => 'uri'],
                            'count' => ['type' => 'integer', 'minimum' => 0],
                        ],
                        'required' => ['name', 'url', 'count'],
                    ],
                ],
                'minFontSize' => ['type' => 'integer', 'minimum' => 8],
                'maxFontSize' => ['type' => 'integer', 'minimum' => 8],
            ],
            'required' => ['tags'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $tags */
        $tags = $data['tags'] ?? [];
        /** @var mixed $rawMinFont */
        $rawMinFont = $data['minFontSize'] ?? null;
        /** @var mixed $rawMaxFont */
        $rawMaxFont = $data['maxFontSize'] ?? null;
        $minFont = is_int($rawMinFont) ? max(8, $rawMinFont) : 12;
        $maxFont = is_int($rawMaxFont) ? max($minFont, $rawMaxFont) : 32;

        $maxCount = 0;
        $minCount = PHP_INT_MAX;

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            /** @var mixed $rawCount */
            $rawCount = $tag['count'] ?? null;
            $count = is_int($rawCount) ? $rawCount : 0;
            $maxCount = max($maxCount, $count);
            $minCount = min($minCount, $count);
        }

        if ($minCount === $maxCount) {
            $minCount = 0;
        }

        $html = '<div class="tag-cloud" role="navigation" aria-label="Tag cloud">';

        /** @var mixed $tag */
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            /** @var mixed $rawName */
            $rawName = $tag['name'] ?? null;
            /** @var mixed $rawUrl */
            $rawUrl = $tag['url'] ?? null;
            /** @var mixed $rawCount */
            $rawCount = $tag['count'] ?? null;
            $name = htmlspecialchars(is_string($rawName) ? $rawName : '', ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars(is_string($rawUrl) ? $rawUrl : '#', ENT_QUOTES, 'UTF-8');
            $count = is_int($rawCount) ? $rawCount : 0;

            $range = $maxCount - $minCount;
            $fontSize = $range > 0
                ? (int) ($minFont + (($count - $minCount) / $range) * ($maxFont - $minFont))
                : $minFont;

            $html .= "<a href=\"$url\" class=\"tag-cloud__tag\" style=\"font-size:{$fontSize}px\" title=\"$name ($count)\">$name</a> ";
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['tags']) || !is_array($data['tags'])) {
            $errors[] = 'tags is required and must be an array';

            return $errors;
        }

        foreach ($data['tags'] as $index => $tag) {
            if (!is_array($tag)) {
                $errors[] = "tags[$index] must be an object";

                continue;
            }

            if (!isset($tag['name']) || !is_string($tag['name'])) {
                $errors[] = "tags[$index].name is required and must be a string";
            }

            if (!isset($tag['url']) || !is_string($tag['url'])) {
                $errors[] = "tags[$index].url is required and must be a string";
            }

            if (!isset($tag['count']) || !is_int($tag['count'])) {
                $errors[] = "tags[$index].count is required and must be an integer";
            } elseif ($tag['count'] < 0) {
                $errors[] = "tags[$index].count must be non-negative";
            }
        }

        if (isset($data['minFontSize']) && (!is_int($data['minFontSize']) || $data['minFontSize'] < 8)) {
            $errors[] = 'minFontSize must be an integer >= 8';
        }

        if (isset($data['maxFontSize']) && (!is_int($data['maxFontSize']) || $data['maxFontSize'] < 8)) {
            $errors[] = 'maxFontSize must be an integer >= 8';
        }

        return $errors;
    }
}
