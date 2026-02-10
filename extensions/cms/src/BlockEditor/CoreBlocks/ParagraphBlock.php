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
final readonly class ParagraphBlock implements BlockTypeInterface
{
    private const array VALID_ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    #[Override]
    public function type(): string
    {
        return 'paragraph';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'alignment' => ['type' => 'string', 'enum' => self::VALID_ALIGNMENTS],
            ],
            'required' => ['text'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $text = htmlspecialchars((string) ($data['text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $alignment = $data['alignment'] ?? null;

        if (is_string($alignment) && in_array($alignment, self::VALID_ALIGNMENTS, true)) {
            return "<p style=\"text-align:{$alignment}\">{$text}</p>";
        }

        return "<p>{$text}</p>";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['text']) || !is_string($data['text'])) {
            $errors[] = 'text is required and must be a string';
        }

        if (isset($data['alignment']) && !in_array($data['alignment'], self::VALID_ALIGNMENTS, true)) {
            $errors[] = 'alignment must be one of: left, center, right, justify';
        }

        return $errors;
    }
}
