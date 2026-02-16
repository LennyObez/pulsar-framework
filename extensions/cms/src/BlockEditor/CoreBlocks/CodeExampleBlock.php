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

/**
 * Enhanced code block with filename header, copy button, and language label.
 *
 * Extends the basic code block with UI affordances commonly needed in
 * documentation and tutorial content types.
 */
#[Internal(reason: 'CMS block type; implementation detail')]
final readonly class CodeExampleBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'code-example';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
                'language' => ['type' => 'string'],
                'filename' => ['type' => 'string'],
                'showCopy' => ['type' => 'boolean', 'default' => true],
            ],
            'required' => ['code'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $rawCode = $data['code'] ?? '';
        $code = htmlspecialchars(is_string($rawCode) ? $rawCode : '', ENT_QUOTES, 'UTF-8');
        $showCopy = ($data['showCopy'] ?? true) ? 'true' : 'false';

        $html = "<div class=\"cms-code-example\" data-show-copy=\"$showCopy\">";

        $filename = $data['filename'] ?? null;

        if (is_string($filename) && $filename !== '') {
            $escapedFilename = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');
            $html .= "<div class=\"cms-code-example__filename\">$escapedFilename</div>";
        }

        $language = $data['language'] ?? null;

        if (is_string($language) && $language !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $language) === 1) {
            $escapedLang = htmlspecialchars($language, ENT_QUOTES, 'UTF-8');
            $html .= "<pre><code class=\"language-$escapedLang\">$code</code></pre>";
        } else {
            $html .= "<pre><code>$code</code></pre>";
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['code']) || !is_string($data['code'])) {
            $errors[] = 'code is required and must be a string';
        }

        if (isset($data['language'])) {
            if (!is_string($data['language'])) {
                $errors[] = 'language must be a string';
            } elseif ($data['language'] !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $data['language']) !== 1) {
                $errors[] = 'language must contain only alphanumeric characters, dashes, and underscores';
            }
        }

        if (isset($data['filename']) && !is_string($data['filename'])) {
            $errors[] = 'filename must be a string';
        }

        return $errors;
    }
}
