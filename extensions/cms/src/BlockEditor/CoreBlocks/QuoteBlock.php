<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class QuoteBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'quote';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'citation' => ['type' => 'string'],
            ],
            'required' => ['text'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $text = htmlspecialchars((string) ($data['text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $citation = $data['citation'] ?? null;

        $html = "<blockquote><p>$text</p>";

        if (is_string($citation) && $citation !== '') {
            $html .= '<cite>' . htmlspecialchars($citation, ENT_QUOTES, 'UTF-8') . '</cite>';
        }

        return $html . '</blockquote>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['text']) || !is_string($data['text'])) {
            $errors[] = 'text is required and must be a string';
        }

        if (isset($data['citation']) && !is_string($data['citation'])) {
            $errors[] = 'citation must be a string';
        }

        return $errors;
    }
}
