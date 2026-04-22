<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function in_array;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class IconBlock implements BlockTypeInterface
{
    private const array VALID_SIZES = ['sm', 'md', 'lg', 'xl'];

    #[Override]
    public function type(): string
    {
        return 'icon';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'size' => ['type' => 'string', 'enum' => self::VALID_SIZES],
                'color' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawName */
        $rawName = $data['name'] ?? null;
        $name = htmlspecialchars(is_string($rawName) ? $rawName : '', ENT_QUOTES, 'UTF-8');
        /** @var mixed $size */
        $size = $data['size'] ?? null;
        /** @var mixed $color */
        $color = $data['color'] ?? null;

        if (!is_string($size) || !in_array($size, self::VALID_SIZES, true)) {
            $size = 'md';
        }

        $cssClass = "icon icon-$name icon--$size";

        $style = '';

        if (is_string($color) && $color !== '') {
            $style = ' style="color:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '"';
        }

        return "<span class=\"$cssClass\"$style aria-hidden=\"true\"></span>";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['name']) || !is_string($data['name'])) {
            $errors[] = 'name is required and must be a string';
        }

        if (isset($data['size']) && (!is_string($data['size']) || !in_array($data['size'], self::VALID_SIZES, true))) {
            $errors[] = 'size must be one of: sm, md, lg, xl';
        }

        return $errors;
    }
}
