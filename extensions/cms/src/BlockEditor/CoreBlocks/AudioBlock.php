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
final readonly class AudioBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'audio';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'src' => ['type' => 'string'],
                'caption' => ['type' => 'string'],
            ],
            'required' => ['src'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $src = htmlspecialchars((string) ($data['src'] ?? ''), ENT_QUOTES, 'UTF-8');
        $caption = $data['caption'] ?? null;

        $html = "<figure class=\"audio-block\"><audio controls src=\"{$src}\"></audio>";

        if (is_string($caption) && $caption !== '') {
            $html .= '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</figcaption>';
        }

        return $html . '</figure>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['src']) || !is_string($data['src'])) {
            $errors[] = 'src is required and must be a string';
        }

        return $errors;
    }
}
