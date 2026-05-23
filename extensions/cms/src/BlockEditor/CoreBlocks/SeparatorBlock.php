<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function in_array;
use function is_string;

#[Internal]
final readonly class SeparatorBlock implements BlockTypeInterface
{
    private const array VALID_STYLES = ['solid', 'dashed', 'dotted', 'wide'];

    #[Override]
    public function type(): string
    {
        return 'separator';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'style' => ['type' => 'string'],
            ],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $style */
        $style = $data['style'] ?? null;

        if (is_string($style) && in_array($style, self::VALID_STYLES, true)) {
            return "<hr class=\"separator separator--$style\">";
        }

        return '<hr class="separator">';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (isset($data['style'])) {
            if (!is_string($data['style'])) {
                $errors[] = 'style must be a string';
            } elseif (!in_array($data['style'], self::VALID_STYLES, true)) {
                $errors[] = 'style must be one of: solid, dashed, dotted, wide';
            }
        }

        return $errors;
    }
}
