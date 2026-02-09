<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function implode;
use function is_array;
use function is_bool;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class ListBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'list';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'ordered' => ['type' => 'boolean'],
            ],
            'required' => ['items', 'ordered'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<string> $items */
        $items = $data['items'] ?? [];
        $ordered = (bool) ($data['ordered'] ?? false);

        $tag = $ordered ? 'ol' : 'ul';
        $listItems = [];

        foreach ($items as $item) {
            $listItems[] = '<li>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        return "<{$tag}>" . implode('', $listItems) . "</{$tag}>";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['items']) || !is_array($data['items'])) {
            $errors[] = 'items is required and must be an array';
        } else {
            foreach ($data['items'] as $index => $item) {
                if (!is_string($item)) {
                    $errors[] = "items[{$index}] must be a string";
                }
            }
        }

        if (!isset($data['ordered']) || !is_bool($data['ordered'])) {
            $errors[] = 'ordered is required and must be a boolean';
        }

        return $errors;
    }
}
