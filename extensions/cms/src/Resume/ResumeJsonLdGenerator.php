<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Resume;

use Pulsar\Api\Api;

use function array_map;
use function is_array;
use function is_string;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Generates JSON-LD Person schema markup from resume content data.
 *
 * Produces a `<script type="application/ld+json">` tag suitable
 * for embedding in the HTML head for structured data / SEO.
 *
 * @psalm-api Public service resolved from the DI container by resume
 *            content rendering; not instantiated by name.
 */
#[Api(since: '1.0.0')]
final class ResumeJsonLdGenerator
{
    /**
     * Generate a JSON-LD script tag from resume data.
     *
     * @param string $name The person's full name
     * @param array<string, mixed> $resumeData Resume field values
     *
     * @return string Complete `<script>` tag with JSON-LD Person schema
     */
    public static function generate(string $name, array $resumeData): string
    {
        /** @var array<string, mixed> $person */
        $person = [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $name,
        ];

        if (isset($resumeData['email']) && is_string($resumeData['email'])) {
            $person['email'] = $resumeData['email'];
        }

        if (isset($resumeData['url']) && is_string($resumeData['url'])) {
            $person['url'] = $resumeData['url'];
        }

        if (isset($resumeData['experience']) && is_array($resumeData['experience'])) {
            $person['hasOccupation'] = array_map(static function (mixed $exp): array {
                $exp = is_array($exp) ? $exp : [];
                $rawTitle = $exp['title'] ?? '';
                $rawLocation = $exp['location'] ?? '';

                return [
                    '@type' => 'Occupation',
                    'name' => is_string($rawTitle) ? $rawTitle : '',
                    'occupationLocation' => [
                        '@type' => 'City',
                        'name' => is_string($rawLocation) ? $rawLocation : '',
                    ],
                ];
            }, $resumeData['experience']);
        }

        if (isset($resumeData['education']) && is_array($resumeData['education'])) {
            $person['alumniOf'] = array_map(static function (mixed $edu): array {
                $edu = is_array($edu) ? $edu : [];
                $rawInstitution = $edu['institution'] ?? '';

                return [
                    '@type' => 'EducationalOrganization',
                    'name' => is_string($rawInstitution) ? $rawInstitution : '',
                ];
            }, $resumeData['education']);
        }

        if (isset($resumeData['skills']) && is_array($resumeData['skills'])) {
            /** @var list<string> $allSkills */
            $allSkills = [];

            foreach ($resumeData['skills'] as $category) {
                if (is_array($category) && isset($category['items']) && is_array($category['items'])) {
                    foreach ($category['items'] as $skill) {
                        $allSkills[] = is_string($skill) ? $skill : '';
                    }
                }
            }

            if ($allSkills !== []) {
                $person['knowsAbout'] = $allSkills;
            }
        }

        if (isset($resumeData['languages']) && is_array($resumeData['languages'])) {
            $person['knowsLanguage'] = array_map(
                static function (mixed $lang): string {
                    $lang = is_array($lang) ? $lang : [];
                    $rawLanguage = $lang['language'] ?? '';

                    return is_string($rawLanguage) ? $rawLanguage : '';
                },
                $resumeData['languages'],
            );
        }

        $json = json_encode($person, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return '<script type="application/ld+json">' . $json . '</script>';
    }
}
