<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function in_array;
use function is_bool;
use function is_int;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class SiteTitleBlock implements BlockTypeInterface
{
    private const array VALID_TAGS = ['h1', 'h2', 'h3', 'p', 'span'];

    #[Override]
    public function type(): string
    {
        return 'site-title';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'tagline' => ['type' => 'string'],
                'tag' => ['type' => 'string', 'enum' => self::VALID_TAGS],
                'linkToHome' => ['type' => 'boolean'],
                'homeUrl' => ['type' => 'string', 'format' => 'uri'],
                'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6],
            ],
            'required' => ['title'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawTitle */
        $rawTitle = $data['title'] ?? null;
        $title = htmlspecialchars(is_string($rawTitle) ? $rawTitle : '', ENT_QUOTES, 'UTF-8');
        /** @var mixed $tagline */
        $tagline = $data['tagline'] ?? null;
        /** @var mixed $rawTag */
        $rawTag = $data['tag'] ?? null;
        $tag = is_string($rawTag) && in_array($rawTag, self::VALID_TAGS, true) ? $rawTag : 'h1';
        /** @var mixed $rawLinkToHome */
        $rawLinkToHome = $data['linkToHome'] ?? null;
        $linkToHome = is_bool($rawLinkToHome) ? $rawLinkToHome : true;
        /** @var mixed $rawHomeUrl */
        $rawHomeUrl = $data['homeUrl'] ?? null;
        $homeUrl = htmlspecialchars(is_string($rawHomeUrl) ? $rawHomeUrl : '/', ENT_QUOTES, 'UTF-8');

        $html = '<div class="site-title-block">';

        $titleContent = $linkToHome ? "<a href=\"$homeUrl\">$title</a>" : $title;
        $html .= "<$tag class=\"site-title-block__title\">$titleContent</$tag>";

        if (is_string($tagline) && $tagline !== '') {
            $escapedTagline = htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8');
            $html .= "<p class=\"site-title-block__tagline\">$escapedTagline</p>";
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['title']) || !is_string($data['title'])) {
            $errors[] = 'title is required and must be a string';
        }

        if (isset($data['tagline']) && !is_string($data['tagline'])) {
            $errors[] = 'tagline must be a string';
        }

        if (isset($data['tag']) && !in_array($data['tag'], self::VALID_TAGS, true)) {
            $errors[] = 'tag must be one of: h1, h2, h3, p, span';
        }

        if (isset($data['linkToHome']) && !is_bool($data['linkToHome'])) {
            $errors[] = 'linkToHome must be a boolean';
        }

        if (isset($data['homeUrl']) && !is_string($data['homeUrl'])) {
            $errors[] = 'homeUrl must be a string';
        }

        if (isset($data['level']) && (!is_int($data['level']) || $data['level'] < 1 || $data['level'] > 6)) {
            $errors[] = 'level must be an integer between 1 and 6';
        }

        return $errors;
    }
}
