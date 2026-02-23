<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function is_int;
use function max;
use function min;

#[Internal]
final readonly class SpacerBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'spacer';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'height' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
            ],
            'required' => ['height'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $height = (int) ($data['height'] ?? 0);
        $height = max(1, min(500, $height));

        return "<div style=\"height:{$height}px\" aria-hidden=\"true\"></div>";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['height']) || !is_int($data['height'])) {
            $errors[] = 'height is required and must be an integer';
        } elseif ($data['height'] < 1 || $data['height'] > 500) {
            $errors[] = 'height must be between 1 and 500';
        }

        return $errors;
    }
}
