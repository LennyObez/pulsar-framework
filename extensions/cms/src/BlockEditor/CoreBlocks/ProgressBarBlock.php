<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_int;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class ProgressBarBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'progress-bar';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'label' => ['type' => 'string'],
                'color' => ['type' => 'string'],
                'showPercentage' => ['type' => 'boolean'],
            ],
            'required' => ['value', 'label'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $value = (int) ($data['value'] ?? 0);
        $label = htmlspecialchars((string) ($data['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $showPercentage = $data['showPercentage'] ?? true;

        $colorStyle = '';

        if (isset($data['color']) && is_string($data['color']) && $data['color'] !== '') {
            $color = htmlspecialchars($data['color'], ENT_QUOTES, 'UTF-8');
            $colorStyle = ";background-color:$color";
        }

        $percentage = '';

        if ($showPercentage) {
            $percentage = "<span class=\"progress__percentage\">$value%</span>";
        }

        return "<div class=\"progress\"><div class=\"progress__label\">$label</div>"
            . '<div class="progress__track"><div class="progress__bar" role="progressbar"'
            . " aria-valuenow=\"$value\" aria-valuemin=\"0\" aria-valuemax=\"100\""
            . " style=\"width:$value%$colorStyle\">$percentage</div></div></div>";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['value']) || !is_int($data['value'])) {
            $errors[] = 'value is required and must be an integer';
        } elseif ($data['value'] < 0 || $data['value'] > 100) {
            $errors[] = 'value must be between 0 and 100';
        }

        if (!isset($data['label']) || !is_string($data['label'])) {
            $errors[] = 'label is required and must be a string';
        }

        return $errors;
    }
}
