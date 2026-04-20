<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_int;
use function is_string;
use function preg_replace;
use function strtolower;
use function trim;

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
        /** @var mixed $rawTextValue */
        $rawTextValue = $data['text'] ?? null;
        $rawText = is_string($rawTextValue) ? $rawTextValue : '';
        $text = htmlspecialchars($rawText, ENT_QUOTES, 'UTF-8');
        /** @var mixed $rawLevel */
        $rawLevel = $data['level'] ?? null;
        $level = is_int($rawLevel) ? $rawLevel : 1;

        if ($level < 1 || $level > 6) {
            $level = 1;
        }

        // Auto-generate anchor ID for table of contents
        /** @var mixed $anchor */
        $anchor = $data['anchor'] ?? null;

        if (!is_string($anchor) || $anchor === '') {
            $anchor = self::slugify($rawText);
        } else {
            $anchor = htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8');
        }

        $className = isset($data['className']) && is_string($data['className'])
            ? ' class="' . htmlspecialchars($data['className'], ENT_QUOTES, 'UTF-8') . '"'
            : '';

        $idAttr = $anchor !== '' ? " id=\"$anchor\"" : '';

        return "<h$level$idAttr$className>$text</h$level>";
    }

    private static function slugify(string $text): string
    {
        $slug = strtolower($text);
        $slug = (string) preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = (string) preg_replace('/[\s-]+/', '-', $slug);

        return trim($slug, '-');
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
