<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;

use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;

/**
 * Resume embed block for the block editor.
 *
 * Embeds specific resume sections (experience, skills, education, etc.)
 * into regular pages, allowing selective display with configurable layout.
 */
#[Internal(reason: 'CMS block type; implementation detail')]
final readonly class ResumeBlock implements BlockTypeInterface
{
    private const array VALID_LAYOUTS = ['single-column', 'two-column'];

    private const array VALID_SECTIONS = [
        'summary', 'experience', 'education', 'skills',
        'languages', 'certifications', 'projects',
    ];

    #[Override]
    public function type(): string
    {
        return 'resume';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'resumeSlug' => ['type' => 'string'],
                'sections' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => self::VALID_SECTIONS],
                ],
                'layout' => [
                    'type' => 'string',
                    'enum' => self::VALID_LAYOUTS,
                    'default' => 'single-column',
                ],
            ],
            'required' => ['resumeSlug'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawSlug */
        $rawSlug = $data['resumeSlug'] ?? '';
        $slug = htmlspecialchars(is_string($rawSlug) ? $rawSlug : '', ENT_QUOTES, 'UTF-8');
        /** @var mixed $rawLayout */
        $rawLayout = $data['layout'] ?? 'single-column';
        $layout = is_string($rawLayout) && in_array($rawLayout, self::VALID_LAYOUTS, true)
            ? $rawLayout
            : 'single-column';

        $sectionsAttr = '';

        if (isset($data['sections']) && is_array($data['sections'])) {
            /** @var list<string> $filtered */
            $filtered = [];

            /** @var mixed $section */
            foreach ($data['sections'] as $section) {
                if (is_string($section) && in_array($section, self::VALID_SECTIONS, true)) {
                    $filtered[] = $section;
                }
            }

            if ($filtered !== []) {
                $json = htmlspecialchars(json_encode($filtered, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
                $sectionsAttr = " data-sections=\"$json\"";
            }
        }

        return '<div class="cms-resume-embed"'
            . " data-resume-slug=\"$slug\""
            . $sectionsAttr
            . " data-layout=\"$layout\">"
            . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['resumeSlug']) || !is_string($data['resumeSlug'])) {
            $errors[] = 'resumeSlug is required and must be a string';
        } elseif ($data['resumeSlug'] === '') {
            $errors[] = 'resumeSlug must not be empty';
        }

        if (isset($data['sections'])) {
            if (!is_array($data['sections'])) {
                $errors[] = 'sections must be an array';
            } else {
                foreach ($data['sections'] as $section) {
                    if (!is_string($section) || !in_array($section, self::VALID_SECTIONS, true)) {
                        $errors[] = 'Each section must be one of: ' . implode(', ', self::VALID_SECTIONS);

                        break;
                    }
                }
            }
        }

        if (isset($data['layout']) && !in_array($data['layout'], self::VALID_LAYOUTS, true)) {
            $errors[] = 'layout must be one of: ' . implode(', ', self::VALID_LAYOUTS);
        }

        return $errors;
    }
}
