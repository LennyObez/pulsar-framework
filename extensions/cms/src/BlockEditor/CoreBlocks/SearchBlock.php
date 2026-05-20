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
final readonly class SearchBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'search';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'placeholder' => ['type' => 'string'],
                'action' => ['type' => 'string', 'format' => 'uri'],
                'buttonText' => ['type' => 'string'],
            ],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawPlaceholder */
        $rawPlaceholder = $data['placeholder'] ?? null;
        /** @var mixed $rawAction */
        $rawAction = $data['action'] ?? null;
        /** @var mixed $rawButtonText */
        $rawButtonText = $data['buttonText'] ?? null;
        $placeholder = htmlspecialchars(
            is_string($rawPlaceholder) ? $rawPlaceholder : 'Search…',
            ENT_QUOTES,
            'UTF-8',
        );
        $action = htmlspecialchars(
            is_string($rawAction) ? $rawAction : '/search',
            ENT_QUOTES,
            'UTF-8',
        );
        $buttonText = htmlspecialchars(
            is_string($rawButtonText) ? $rawButtonText : 'Search',
            ENT_QUOTES,
            'UTF-8',
        );

        return '<form class="search-block" role="search" method="get" action="' . $action . '">'
            . '<label for="search-block-input" class="sr-only">' . $buttonText . '</label>'
            . '<input type="search" id="search-block-input" name="q" placeholder="' . $placeholder . '">'
            . '<button type="submit">' . $buttonText . '</button>'
            . '</form>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (isset($data['placeholder']) && !is_string($data['placeholder'])) {
            $errors[] = 'placeholder must be a string';
        }

        if (isset($data['action']) && !is_string($data['action'])) {
            $errors[] = 'action must be a string';
        }

        if (isset($data['buttonText']) && !is_string($data['buttonText'])) {
            $errors[] = 'buttonText must be a string';
        }

        return $errors;
    }
}
