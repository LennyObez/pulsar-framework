<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_array;
use function is_string;

use const ENT_QUOTES;

/**
 * Before/after image comparison block.
 *
 * Renders a <cms-image-compare> custom element with data attributes
 * for the before and after images.
 */
#[Internal]
final readonly class CompareBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'compare';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'beforeImage' => [
                    'type' => 'object',
                    'properties' => [
                        'src' => ['type' => 'string'],
                        'alt' => ['type' => 'string'],
                    ],
                    'required' => ['src', 'alt'],
                ],
                'afterImage' => [
                    'type' => 'object',
                    'properties' => [
                        'src' => ['type' => 'string'],
                        'alt' => ['type' => 'string'],
                    ],
                    'required' => ['src', 'alt'],
                ],
                'caption' => ['type' => 'string'],
                'beforeLabel' => ['type' => 'string'],
                'afterLabel' => ['type' => 'string'],
            ],
            'required' => ['beforeImage', 'afterImage'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var array<string, mixed> $before */
        $before = $data['beforeImage'] ?? [];
        /** @var array<string, mixed> $after */
        $after = $data['afterImage'] ?? [];

        $beforeSrc = htmlspecialchars(is_string($before['src'] ?? null) ? $before['src'] : '', ENT_QUOTES, 'UTF-8');
        $beforeAlt = htmlspecialchars(is_string($before['alt'] ?? null) ? $before['alt'] : '', ENT_QUOTES, 'UTF-8');
        $afterSrc = htmlspecialchars(is_string($after['src'] ?? null) ? $after['src'] : '', ENT_QUOTES, 'UTF-8');
        $afterAlt = htmlspecialchars(is_string($after['alt'] ?? null) ? $after['alt'] : '', ENT_QUOTES, 'UTF-8');
        $beforeLabel = htmlspecialchars(is_string($data['beforeLabel'] ?? null) ? $data['beforeLabel'] : 'Before', ENT_QUOTES, 'UTF-8');
        $afterLabel = htmlspecialchars(is_string($data['afterLabel'] ?? null) ? $data['afterLabel'] : 'After', ENT_QUOTES, 'UTF-8');

        $html = '<cms-image-compare'
            . " data-before-src=\"$beforeSrc\""
            . " data-before-alt=\"$beforeAlt\""
            . " data-after-src=\"$afterSrc\""
            . " data-after-alt=\"$afterAlt\""
            . " data-before-label=\"$beforeLabel\""
            . " data-after-label=\"$afterLabel\""
            . '></cms-image-compare>';

        $caption = $data['caption'] ?? null;

        if (is_string($caption) && $caption !== '') {
            $html = '<figure>' . $html . '<figcaption>'
                . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8')
                . '</figcaption></figure>';
        }

        return $html;
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['beforeImage']) || !is_array($data['beforeImage'])) {
            $errors[] = 'beforeImage is required and must be an object';
        } else {
            if (!isset($data['beforeImage']['src']) || !is_string($data['beforeImage']['src'])) {
                $errors[] = 'beforeImage.src is required and must be a string';
            }

            if (!isset($data['beforeImage']['alt']) || !is_string($data['beforeImage']['alt'])) {
                $errors[] = 'beforeImage.alt is required and must be a string';
            }
        }

        if (!isset($data['afterImage']) || !is_array($data['afterImage'])) {
            $errors[] = 'afterImage is required and must be an object';
        } else {
            if (!isset($data['afterImage']['src']) || !is_string($data['afterImage']['src'])) {
                $errors[] = 'afterImage.src is required and must be a string';
            }

            if (!isset($data['afterImage']['alt']) || !is_string($data['afterImage']['alt'])) {
                $errors[] = 'afterImage.alt is required and must be a string';
            }
        }

        return $errors;
    }
}
