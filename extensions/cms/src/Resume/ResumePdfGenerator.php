<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Resume;

use Pulsar\Api\Api;

use function array_map;
use function htmlspecialchars;
use function implode;
use function is_string;

use const ENT_QUOTES;

/**
 * Generates a print-optimized HTML document from resume content data.
 *
 * The output is designed for browser print-to-PDF with proper page breaks,
 * clean typography, and print media CSS. No external dependencies required.
 *
 * @psalm-api Public service resolved from the DI container by resume
 *            export endpoints; not instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final class ResumePdfGenerator
{
    /**
     * Generate a complete print-friendly HTML document for a resume.
     *
     * @param string $name The person's full name
     * @param array{
     *     summary?: string,
     *     experience?: list<array{title?: string, company?: string, location?: string, period?: string, description?: string}>,
     *     education?: list<array{degree?: string, institution?: string, year?: string}>,
     *     skills?: list<array{category?: string, items?: list<string>}>,
     *     languages?: list<array{language?: string, level?: string}>,
     *     certifications?: list<array{name?: string, issuer?: string, year?: string}>,
     *     projects?: list<array{name?: string, description?: string}>,
     * } $resumeData Resume field values
     *
     * @return string Complete HTML document with embedded print styles
     */
    public static function generatePrintHtml(string $name, array $resumeData): string
    {
        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $sections = [];

        // Summary
        $summary = $resumeData['summary'] ?? '';
        if ($summary !== '') {
            $sections[] = '<section class="resume-section"><h2>Summary</h2>'
                . '<p>' . $e($summary) . '</p></section>';
        }

        // Experience
        if (isset($resumeData['experience']) && $resumeData['experience'] !== []) {
            $items = [];

            foreach ($resumeData['experience'] as $exp) {
                $title = $e(self::stringField($exp, 'title'));
                $company = $e(self::stringField($exp, 'company'));
                $location = $e(self::stringField($exp, 'location'));
                $period = $e(self::stringField($exp, 'period'));
                $description = $e(self::stringField($exp, 'description'));

                $items[] = '<div class="resume-entry">'
                    . "<h3>$title</h3>"
                    . "<div class=\"resume-meta\">$company"
                    . ($location !== '' ? " &middot; $location" : '')
                    . ($period !== '' ? " &middot; $period" : '')
                    . '</div>'
                    . ($description !== '' ? "<p>$description</p>" : '')
                    . '</div>';
            }

            if ($items !== []) {
                $sections[] = '<section class="resume-section"><h2>Experience</h2>'
                    . implode('', $items) . '</section>';
            }
        }

        // Education
        if (isset($resumeData['education']) && $resumeData['education'] !== []) {
            $items = [];

            foreach ($resumeData['education'] as $edu) {
                $degree = $e(self::stringField($edu, 'degree'));
                $institution = $e(self::stringField($edu, 'institution'));
                $year = $e(self::stringField($edu, 'year'));

                $items[] = '<div class="resume-entry">'
                    . "<h3>$degree</h3>"
                    . "<div class=\"resume-meta\">$institution"
                    . ($year !== '' ? " &middot; $year" : '')
                    . '</div></div>';
            }

            if ($items !== []) {
                $sections[] = '<section class="resume-section"><h2>Education</h2>'
                    . implode('', $items) . '</section>';
            }
        }

        // Skills
        if (isset($resumeData['skills']) && $resumeData['skills'] !== []) {
            $items = [];

            foreach ($resumeData['skills'] as $category) {
                $catName = $e(self::stringField($category, 'category'));
                $skills = $category['items'] ?? [];

                if ($skills !== []) {
                    $skillList = implode(', ', array_map(static fn(string $s): string => $e($s), $skills));
                    $items[] = "<div class=\"resume-entry\"><strong>$catName:</strong> $skillList</div>";
                }
            }

            if ($items !== []) {
                $sections[] = '<section class="resume-section"><h2>Skills</h2>'
                    . implode('', $items) . '</section>';
            }
        }

        // Languages
        if (isset($resumeData['languages']) && $resumeData['languages'] !== []) {
            $items = [];

            foreach ($resumeData['languages'] as $lang) {
                $language = $e(self::stringField($lang, 'language'));
                $level = $e(self::stringField($lang, 'level'));
                $items[] = "<li>$language" . ($level !== '' ? ": $level" : '') . '</li>';
            }

            if ($items !== []) {
                $sections[] = '<section class="resume-section"><h2>Languages</h2><ul>'
                    . implode('', $items) . '</ul></section>';
            }
        }

        // Certifications
        if (isset($resumeData['certifications']) && $resumeData['certifications'] !== []) {
            $items = [];

            foreach ($resumeData['certifications'] as $cert) {
                $certName = $e(self::stringField($cert, 'name'));
                $issuer = $e(self::stringField($cert, 'issuer'));
                $year = $e(self::stringField($cert, 'year'));
                $items[] = "<li>$certName"
                    . ($issuer !== '' ? ": $issuer" : '')
                    . ($year !== '' ? " ($year)" : '')
                    . '</li>';
            }

            if ($items !== []) {
                $sections[] = '<section class="resume-section"><h2>Certifications</h2><ul>'
                    . implode('', $items) . '</ul></section>';
            }
        }

        // Projects
        if (isset($resumeData['projects']) && $resumeData['projects'] !== []) {
            $items = [];

            foreach ($resumeData['projects'] as $project) {
                $projectName = $e(self::stringField($project, 'name'));
                $description = $e(self::stringField($project, 'description'));
                $items[] = '<div class="resume-entry">'
                    . "<h3>$projectName</h3>"
                    . ($description !== '' ? "<p>$description</p>" : '')
                    . '</div>';
            }

            if ($items !== []) {
                $sections[] = '<section class="resume-section"><h2>Projects</h2>'
                    . implode('', $items) . '</section>';
            }
        }

        $escapedName = $e($name);
        $body = implode("\n", $sections);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>$escapedName | Resume</title>
            <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: #1a1a1a; line-height: 1.6; max-width: 800px; margin: 0 auto; padding: 40px 24px; }
            h1 { font-size: 28px; margin-bottom: 24px; border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; }
            h2 { font-size: 18px; text-transform: uppercase; letter-spacing: 1px; color: #333; margin-bottom: 12px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
            h3 { font-size: 16px; margin-bottom: 2px; }
            .resume-section { margin-bottom: 24px; page-break-inside: avoid; }
            .resume-entry { margin-bottom: 12px; }
            .resume-meta { font-size: 14px; color: #555; margin-bottom: 4px; }
            ul { padding-left: 20px; }
            li { margin-bottom: 4px; }
            @media print {
              body { padding: 0; font-size: 11pt; }
              h1 { font-size: 22pt; }
              .resume-section { page-break-inside: avoid; }
            }
            </style>
            </head>
            <body>
            <h1>$escapedName</h1>
            $body
            </body>
            </html>
            HTML;
    }

    /**
     * Read a string field from a loosely-typed array, falling back to '' when
     * missing or wrong-typed.
     *
     * @param array<array-key, mixed> $row
     */
    private static function stringField(array $row, string $key): string
    {
        /** @var mixed $value */
        $value = $row[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
