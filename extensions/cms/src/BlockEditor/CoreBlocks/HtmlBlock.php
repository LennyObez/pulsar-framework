<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;

use function is_string;

#[Internal]
final readonly class HtmlBlock implements BlockTypeInterface
{
    public function __construct(
        private SafeHtmlPolicy $sanitizer,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'html';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'html' => ['type' => 'string'],
            ],
            'required' => ['html'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $html */
        $html = $data['html'] ?? '';

        return $this->sanitizer->sanitize(is_string($html) ? $html : '');
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['html']) || !is_string($data['html'])) {
            $errors[] = 'html is required and must be a string';
        }

        return $errors;
    }
}
