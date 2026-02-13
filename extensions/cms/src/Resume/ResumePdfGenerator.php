<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Resume;

use Pulsar\Api\Api;

use function htmlspecialchars;
use function implode;
use function is_array;
use function is_string;

use const ENT_QUOTES;

/**
 * Generates a print-optimized HTML document from resume content data.
 *
 * The output is designed for browser print-to-PDF with proper page breaks,
 * clean typography, and print media CSS. No external dependencies required.
 */
#[Api(since: '1.0.0')]
final class ResumePdfGenerator
{
    /**
     * Generate a complete print-friendly HTML document for a resume.
     *
     * @param string $name The person's full name
     * @param array<string, mixed> $resumeData Resume field values (summary, experience, education, skills, languages, certifications, projects)
     *
     * @return string Complete HTML document with embedded print styles
     */
    public static function generatePrintHtml(string $name, array $resumeData): string
    {
        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $sections = [];

        // Summary
        if (isset($resumeData['summary']) && is_string($resumeData['summary']) && $resumeData['summary'] !== '') {
            $sections[] = '<section class="resume-section"><h2>Summary</h2>'
                . '<p>' . $e($resumeData['summary']) . '</p></section>';
        }

        // Experience
        if (isset($resumeData['experience']) && is_array($resumeData['experience']) && $resumeData['experience'] !== []) {
            $items = [];

            foreach ($resumeData['experience'] as $exp) {
                if (!is_array($exp)) {
                    continue;
                }

                $rawTitle = $exp['title'] ?? '';
                $title = $e(is_string($rawTitle) ? $rawTitle : '');
                $rawCompany = $exp['company'] ?? '';
                $company = $e(is_string($rawCompany) ? $rawCompany : '');
                $rawLocation = $exp['location'] ?? '';
                $location = $e(is_string($rawLocation) ? $rawLocation : '');
                $rawPeriod = $exp['period'] ?? '';
                $period = $e(is_string($rawPeriod) ? $rawPeriod : '');
                $rawDescription = $exp['description'] ?? '';
                $description = $e(is_string($rawDescription) ? $rawDescription : '');

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
        if (isset($resumeData['education']) && is_array($resumeData['education']) && $resumeData['education'] !== []) {
            $items = [];

            foreach ($resumeData['education'] as $edu) {
                if (!is_array($edu)) {
                    continue;
                }

                $rawDegree = $edu['degree'] ?? '';
                $degree = $e(is_string($rawDegree) ? $rawDegree : '');
                $rawInstitution = $edu['institution'] ?? '';
                $institution = $e(is_string($rawInstitution) ? $rawInstitution : '');
                $rawYear = $edu['year'] ?? '';
                $year = $e(is_string($rawYear) ? $rawYear : '');

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
        if (isset($resumeData['skills']) && is_array($resumeData['skills']) && $resumeData['skills'] !== []) {
            $items = [];

            foreach ($resumeData['skills'] as $category) {
                if (!is_array($category)) {
                    continue;
                }

                $rawCatName = $category['category'] ?? '';
                $catName = $e(is_string($rawCatName) ? $rawCatName : '');
                $skills = $category['items'] ?? [];

                if (is_array($skills) && $skills !== []) {
                    $skillList = implode(', ', array_map(static fn(mixed $s): string => $e(is_string($s) ? $s : ''), $skills));
                    $items[] = "<div class=\"resume-entry\"><strong>$catName:</strong> $skillList</div>";
                }
            }

            if ($items !== []) {
                $sections[] = '<section class="resume-section"><h2>Skills</h2>'
                    . implode('', $items) . '</section>';
            }
        }

        // Languages
        if (isset($resumeData['languages']) && is_array($resumeData['languages']) && $resumeData['languages'] !== []) {
            $items = [];

            foreach ($resumeData['languages'] as $lang) {
                if (!is_array($lang)) {
                    continue;
                }

                $rawLanguage = $lang['language'] ?? '';
                $language = $e(is_string($rawLanguage) ? $rawLanguage : '');
                $rawLevel = $lang['level'] ?? '';
                $level = $e(is_string($rawLevel) ? $rawLevel : '');
                $items[] = "<li>$language" . ($level !== '' ? " — $level" : '') . '</li>';
            }

            if ($items !== []) {
                $sections[] = '<section class="resume-section"><h2>Languages</h2><ul>'
                    . implode('', $items) . '</ul></section>';
            }
        }

        // Certifications
        if (isset($resumeData['certifications']) && is_array($resumeData['certifications']) && $resumeData['certifications'] !== []) {
            $items = [];

            foreach ($resumeData['certifications'] as $cert) {
                if (!is_array($cert)) {
                    continue;
                }

                $rawCertName = $cert['name'] ?? '';
                $certName = $e(is_string($rawCertName) ? $rawCertName : '');
                $rawIssuer = $cert['issuer'] ?? '';
                $issuer = $e(is_string($rawIssuer) ? $rawIssuer : '');
                $rawCertYear = $cert['year'] ?? '';
                $year = $e(is_string($rawCertYear) ? $rawCertYear : '');
                $items[] = "<li>$certName"
                    . ($issuer !== '' ? " — $issuer" : '')
                    . ($year !== '' ? " ($year)" : '')
                    . '</li>';
            }

            if ($items !== []) {
                $sections[] = '<section class="resume-section"><h2>Certifications</h2><ul>'
                    . implode('', $items) . '</ul></section>';
            }
        }

        // Projects
        if (isset($resumeData['projects']) && is_array($resumeData['projects']) && $resumeData['projects'] !== []) {
            $items = [];

            foreach ($resumeData['projects'] as $project) {
                if (!is_array($project)) {
                    continue;
                }

                $rawProjectName = $project['name'] ?? '';
                $projectName = $e(is_string($rawProjectName) ? $rawProjectName : '');
                $rawProjDescription = $project['description'] ?? '';
                $description = $e(is_string($rawProjDescription) ? $rawProjDescription : '');
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
            <title>$escapedName — Resume</title>
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
}
