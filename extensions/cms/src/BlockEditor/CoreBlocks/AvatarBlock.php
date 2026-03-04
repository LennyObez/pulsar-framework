<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function count;
use function htmlspecialchars;
use function in_array;
use function is_int;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class AvatarBlock implements BlockTypeInterface
{
    private const array VALID_SIZES = ['sm', 'md', 'lg', 'xl'];
    private const array VALID_SHAPES = ['circle', 'square', 'rounded'];

    #[Override]
    public function type(): string
    {
        return 'avatar';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'src' => ['type' => 'string', 'format' => 'uri'],
                'alt' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'size' => ['type' => 'string', 'enum' => self::VALID_SIZES],
                'shape' => ['type' => 'string', 'enum' => self::VALID_SHAPES],
                'width' => ['type' => 'integer', 'minimum' => 16, 'maximum' => 512],
            ],
            'required' => ['alt'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $src = $data['src'] ?? null;
        $alt = htmlspecialchars(is_string($data['alt'] ?? null) ? $data['alt'] : '', ENT_QUOTES, 'UTF-8');
        $name = is_string($data['name'] ?? null) ? $data['name'] : '';
        $size = is_string($data['size'] ?? null) && in_array($data['size'], self::VALID_SIZES, true)
            ? $data['size']
            : 'md';
        $shape = is_string($data['shape'] ?? null) && in_array($data['shape'], self::VALID_SHAPES, true)
            ? $data['shape']
            : 'circle';
        $width = is_int($data['width'] ?? null) ? $data['width'] : null;

        $widthAttr = $width !== null ? " width=\"$width\" height=\"$width\"" : '';

        $html = "<div class=\"avatar-block avatar-block--$size avatar-block--$shape\">";

        if (is_string($src) && $src !== '') {
            $escapedSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
            $html .= "<img src=\"$escapedSrc\" alt=\"$alt\" class=\"avatar-block__image\"$widthAttr loading=\"lazy\">";
        } else {
            $initials = $this->extractInitials($name);
            $escapedInitials = htmlspecialchars($initials, ENT_QUOTES, 'UTF-8');
            $html .= "<span class=\"avatar-block__initials\" role=\"img\" aria-label=\"$alt\">$escapedInitials</span>";
        }

        return $html . '</div>';
    }

    private function extractInitials(string $name): string
    {
        if ($name === '') {
            return '?';
        }

        $parts = explode(' ', trim($name));
        $first = mb_substr($parts[0], 0, 1, 'UTF-8');

        if (count($parts) > 1) {
            $last = mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8');

            return mb_strtoupper($first . $last, 'UTF-8');
        }

        return mb_strtoupper($first, 'UTF-8');
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['alt']) || !is_string($data['alt'])) {
            $errors[] = 'alt is required and must be a string';
        }

        if (isset($data['src']) && !is_string($data['src'])) {
            $errors[] = 'src must be a string';
        }

        if (isset($data['name']) && !is_string($data['name'])) {
            $errors[] = 'name must be a string';
        }

        if (isset($data['size']) && !in_array($data['size'], self::VALID_SIZES, true)) {
            $errors[] = 'size must be one of: sm, md, lg, xl';
        }

        if (isset($data['shape']) && !in_array($data['shape'], self::VALID_SHAPES, true)) {
            $errors[] = 'shape must be one of: circle, square, rounded';
        }

        if (isset($data['width']) && (!is_int($data['width']) || $data['width'] < 16 || $data['width'] > 512)) {
            $errors[] = 'width must be an integer between 16 and 512';
        }

        return $errors;
    }
}
