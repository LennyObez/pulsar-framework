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
final readonly class CtaBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'cta';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'url' => ['type' => 'string', 'format' => 'uri'],
                'style' => ['type' => 'string'],
            ],
            'required' => ['text', 'url'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $text = htmlspecialchars((string) ($data['text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars((string) ($data['url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $style = $data['style'] ?? null;

        $cssClass = 'cta-button';

        if (is_string($style) && $style !== '') {
            $cssClass .= ' cta-button--' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8');
        }

        return "<a href=\"$url\" class=\"$cssClass\">$text</a>";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['text']) || !is_string($data['text'])) {
            $errors[] = 'text is required and must be a string';
        }

        if (!isset($data['url']) || !is_string($data['url'])) {
            $errors[] = 'url is required and must be a string';
        }

        return $errors;
    }
}
