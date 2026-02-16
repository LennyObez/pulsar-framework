<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_string;
use function preg_match;

use const ENT_QUOTES;

#[Internal]
final readonly class CodeBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'code';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
                'language' => ['type' => 'string'],
            ],
            'required' => ['code'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $code = htmlspecialchars(is_string($data['code'] ?? null) ? $data['code'] : '', ENT_QUOTES, 'UTF-8');
        $language = $data['language'] ?? null;

        if (is_string($language) && $language !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $language) === 1) {
            $escapedLang = htmlspecialchars($language, ENT_QUOTES, 'UTF-8');

            return "<pre><code class=\"language-$escapedLang\">$code</code></pre>";
        }

        return "<pre><code>$code</code></pre>";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['code']) || !is_string($data['code'])) {
            $errors[] = 'code is required and must be a string';
        }

        if (isset($data['language']) && !is_string($data['language'])) {
            $errors[] = 'language must be a string';
        }

        return $errors;
    }
}
