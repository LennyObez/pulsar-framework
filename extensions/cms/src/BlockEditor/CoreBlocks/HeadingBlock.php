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
final readonly class HeadingBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'heading';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6],
            ],
            'required' => ['text', 'level'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $text = htmlspecialchars((string) ($data['text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $level = (int) ($data['level'] ?? 1);

        if ($level < 1 || $level > 6) {
            $level = 1;
        }

        return "<h$level>$text</h$level>";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['text']) || !is_string($data['text'])) {
            $errors[] = 'text is required and must be a string';
        }

        if (!isset($data['level']) || !is_int($data['level'])) {
            $errors[] = 'level is required and must be an integer';
        } elseif ($data['level'] < 1 || $data['level'] > 6) {
            $errors[] = 'level must be between 1 and 6';
        }

        return $errors;
    }
}
