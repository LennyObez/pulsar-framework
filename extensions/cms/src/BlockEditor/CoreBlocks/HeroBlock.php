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
final readonly class HeroBlock implements BlockTypeInterface
{
    private const array VALID_ALIGNMENTS = ['left', 'center', 'right'];

    #[Override]
    public function type(): string
    {
        return 'hero';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'subtitle' => ['type' => 'string'],
                'backgroundImage' => ['type' => 'string'],
                'ctaText' => ['type' => 'string'],
                'ctaUrl' => ['type' => 'string', 'format' => 'uri'],
                'alignment' => ['type' => 'string', 'enum' => self::VALID_ALIGNMENTS],
            ],
            'required' => ['title'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $title = htmlspecialchars((string) ($data['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $alignment = 'center';

        if (isset($data['alignment']) && is_string($data['alignment']) && in_array($data['alignment'], self::VALID_ALIGNMENTS, true)) {
            $alignment = $data['alignment'];
        }

        $bgStyle = '';

        if (isset($data['backgroundImage']) && is_string($data['backgroundImage']) && $data['backgroundImage'] !== '') {
            $bgUrl = htmlspecialchars($data['backgroundImage'], ENT_QUOTES, 'UTF-8');
            $bgStyle = " style=\"background-image:url({$bgUrl})\"";
        }

        $html = "<section class=\"hero hero--{$alignment}\"{$bgStyle}><div class=\"hero__content\"><h1>{$title}</h1>";

        if (isset($data['subtitle']) && is_string($data['subtitle']) && $data['subtitle'] !== '') {
            $subtitle = htmlspecialchars($data['subtitle'], ENT_QUOTES, 'UTF-8');
            $html .= "<p class=\"hero__subtitle\">{$subtitle}</p>";
        }

        if (isset($data['ctaUrl']) && is_string($data['ctaUrl']) && $data['ctaUrl'] !== ''
            && isset($data['ctaText']) && is_string($data['ctaText']) && $data['ctaText'] !== '') {
            $ctaUrl = htmlspecialchars($data['ctaUrl'], ENT_QUOTES, 'UTF-8');
            $ctaText = htmlspecialchars($data['ctaText'], ENT_QUOTES, 'UTF-8');
            $html .= "<a href=\"{$ctaUrl}\" class=\"hero__cta\">{$ctaText}</a>";
        }

        return $html . '</div></section>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['title']) || !is_string($data['title'])) {
            $errors[] = 'title is required and must be a string';
        }

        if (isset($data['alignment']) && (!is_string($data['alignment']) || !in_array($data['alignment'], self::VALID_ALIGNMENTS, true))) {
            $errors[] = 'alignment must be one of: left, center, right';
        }

        return $errors;
    }
}
