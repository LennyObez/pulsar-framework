<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_int;
use function is_string;
use function str_repeat;

use const ENT_QUOTES;

#[Internal]
final readonly class TestimonialBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'testimonial';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'quote' => ['type' => 'string'],
                'author' => ['type' => 'string'],
                'role' => ['type' => 'string'],
                'avatarUrl' => ['type' => 'string', 'format' => 'uri'],
                'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            ],
            'required' => ['quote', 'author'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawQuote */
        $rawQuote = $data['quote'] ?? null;
        /** @var mixed $rawAuthor */
        $rawAuthor = $data['author'] ?? null;
        $quote = htmlspecialchars(is_string($rawQuote) ? $rawQuote : '', ENT_QUOTES, 'UTF-8');
        $author = htmlspecialchars(is_string($rawAuthor) ? $rawAuthor : '', ENT_QUOTES, 'UTF-8');

        $html = '<blockquote class="testimonial">';

        if (isset($data['rating']) && is_int($data['rating']) && $data['rating'] >= 1 && $data['rating'] <= 5) {
            $stars = str_repeat("\u{2605}", $data['rating']);
            $html .= "<div class=\"testimonial__rating\">$stars</div>";
        }

        $html .= "<p class=\"testimonial__quote\">$quote</p><footer class=\"testimonial__footer\">";

        if (isset($data['avatarUrl']) && is_string($data['avatarUrl']) && $data['avatarUrl'] !== '') {
            $avatarUrl = htmlspecialchars($data['avatarUrl'], ENT_QUOTES, 'UTF-8');
            $html .= "<img src=\"$avatarUrl\" alt=\"$author\" class=\"testimonial__avatar\">";
        }

        $html .= "<cite class=\"testimonial__author\">$author</cite>";

        if (isset($data['role']) && is_string($data['role']) && $data['role'] !== '') {
            $role = htmlspecialchars($data['role'], ENT_QUOTES, 'UTF-8');
            $html .= "<span class=\"testimonial__role\">$role</span>";
        }

        return $html . '</footer></blockquote>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['quote']) || !is_string($data['quote'])) {
            $errors[] = 'quote is required and must be a string';
        }

        if (!isset($data['author']) || !is_string($data['author'])) {
            $errors[] = 'author is required and must be a string';
        }

        if (isset($data['rating']) && (!is_int($data['rating']) || $data['rating'] < 1 || $data['rating'] > 5)) {
            $errors[] = 'rating must be an integer between 1 and 5';
        }

        return $errors;
    }
}
