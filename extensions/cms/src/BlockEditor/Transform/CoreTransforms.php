<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Transform;

use Pulsar\Api\Api;

use function implode;
use function is_string;

/**
 * Built-in block transforms for core block types.
 *
 * Registers standard transforms: Heading <-> Paragraph, List <-> Paragraph,
 * Quote <-> Paragraph, preserving content during conversion.
 *
 * @psalm-api Registered via static `register()` from the cms BlockEditor
 *            service provider; not new'd by name.
 * @api
 */
#[Api(since: '1.0.0')]
final class CoreTransforms
{
    /**
     * Register all core transforms with the given registry.
     */
    public static function register(TransformRegistry $registry): void
    {
        // Heading -> Paragraph
        $registry->register(new BlockTransform(
            'heading',
            'paragraph',
            static fn(array $data): array => [
                'text' => $data['text'] ?? '',
            ],
        ));

        // Paragraph -> Heading
        $registry->register(new BlockTransform(
            'paragraph',
            'heading',
            static fn(array $data): array => [
                'text' => $data['text'] ?? '',
                'level' => 2,
            ],
        ));

        // List -> Paragraph (join items)
        $registry->register(new BlockTransform(
            'list',
            'paragraph',
            static function (array $data): array {
                /** @var list<mixed> $items */
                $items = $data['items'] ?? [];
                $text = implode("\n", array_filter($items, 'is_string'));

                return ['text' => $text];
            },
        ));

        // Paragraph -> List (split by newlines)
        $registry->register(new BlockTransform(
            'paragraph',
            'list',
            static function (array $data): array {
                $text = is_string($data['text'] ?? null) ? $data['text'] : '';
                $items = array_filter(explode("\n", $text), static fn(string $s): bool => $s !== '');

                return [
                    'items' => array_values($items),
                    'ordered' => false,
                ];
            },
        ));

        // Quote -> Paragraph
        $registry->register(new BlockTransform(
            'quote',
            'paragraph',
            static fn(array $data): array => [
                'text' => $data['text'] ?? '',
            ],
        ));

        // Paragraph -> Quote
        $registry->register(new BlockTransform(
            'paragraph',
            'quote',
            static fn(array $data): array => [
                'text' => $data['text'] ?? '',
            ],
        ));

        // Heading -> Quote
        $registry->register(new BlockTransform(
            'heading',
            'quote',
            static fn(array $data): array => [
                'text' => $data['text'] ?? '',
            ],
        ));

        // Quote -> Heading
        $registry->register(new BlockTransform(
            'quote',
            'heading',
            static fn(array $data): array => [
                'text' => $data['text'] ?? '',
                'level' => 2,
            ],
        ));
    }
}
