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
final readonly class CodeComparisonBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'code-comparison';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'leftLabel' => ['type' => 'string'],
                'leftCode' => ['type' => 'string'],
                'leftLanguage' => ['type' => 'string'],
                'rightLabel' => ['type' => 'string'],
                'rightCode' => ['type' => 'string'],
                'rightLanguage' => ['type' => 'string'],
            ],
            'required' => ['leftLabel', 'leftCode', 'rightLabel', 'rightCode'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $title */
        $title = $data['title'] ?? null;
        /** @var mixed $rawLeftLabel */
        $rawLeftLabel = $data['leftLabel'] ?? null;
        /** @var mixed $rawLeftCode */
        $rawLeftCode = $data['leftCode'] ?? null;
        /** @var mixed $rawRightLabel */
        $rawRightLabel = $data['rightLabel'] ?? null;
        /** @var mixed $rawRightCode */
        $rawRightCode = $data['rightCode'] ?? null;
        $leftLabel = htmlspecialchars(is_string($rawLeftLabel) ? $rawLeftLabel : '', ENT_QUOTES, 'UTF-8');
        $leftCode = htmlspecialchars(is_string($rawLeftCode) ? $rawLeftCode : '', ENT_QUOTES, 'UTF-8');
        $leftLang = $this->sanitizeLanguage($data['leftLanguage'] ?? null);
        $rightLabel = htmlspecialchars(is_string($rawRightLabel) ? $rawRightLabel : '', ENT_QUOTES, 'UTF-8');
        $rightCode = htmlspecialchars(is_string($rawRightCode) ? $rawRightCode : '', ENT_QUOTES, 'UTF-8');
        $rightLang = $this->sanitizeLanguage($data['rightLanguage'] ?? null);

        $html = '<div class="code-comparison-block">';

        if (is_string($title) && $title !== '') {
            $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            $html .= "<h3 class=\"code-comparison-block__title\">$escapedTitle</h3>";
        }

        $html .= '<div class="code-comparison-block__panels">';

        // Left panel
        $html .= '<div class="code-comparison-block__panel">';
        $html .= "<div class=\"code-comparison-block__label\">$leftLabel</div>";
        $leftClass = $leftLang !== '' ? " class=\"language-$leftLang\"" : '';
        $html .= "<pre><code$leftClass>$leftCode</code></pre>";
        $html .= '</div>';

        // Right panel
        $html .= '<div class="code-comparison-block__panel">';
        $html .= "<div class=\"code-comparison-block__label\">$rightLabel</div>";
        $rightClass = $rightLang !== '' ? " class=\"language-$rightLang\"" : '';
        $html .= "<pre><code$rightClass>$rightCode</code></pre>";
        $html .= '</div>';

        return $html . '</div></div>';
    }

    private function sanitizeLanguage(mixed $language): string
    {
        if (!is_string($language) || $language === '') {
            return '';
        }

        if (preg_match('/^[a-zA-Z0-9_-]+$/', $language) === 1) {
            return htmlspecialchars($language, ENT_QUOTES, 'UTF-8');
        }

        return '';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['leftLabel']) || !is_string($data['leftLabel'])) {
            $errors[] = 'leftLabel is required and must be a string';
        }

        if (!isset($data['leftCode']) || !is_string($data['leftCode'])) {
            $errors[] = 'leftCode is required and must be a string';
        }

        if (!isset($data['rightLabel']) || !is_string($data['rightLabel'])) {
            $errors[] = 'rightLabel is required and must be a string';
        }

        if (!isset($data['rightCode']) || !is_string($data['rightCode'])) {
            $errors[] = 'rightCode is required and must be a string';
        }

        if (isset($data['leftLanguage']) && !is_string($data['leftLanguage'])) {
            $errors[] = 'leftLanguage must be a string';
        }

        if (isset($data['rightLanguage']) && !is_string($data['rightLanguage'])) {
            $errors[] = 'rightLanguage must be a string';
        }

        if (isset($data['title']) && !is_string($data['title'])) {
            $errors[] = 'title must be a string';
        }

        return $errors;
    }
}
