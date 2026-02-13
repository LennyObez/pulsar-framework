<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Resume;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Resume\ResumeJsonLdGenerator;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ResumeJsonLdGenerator::class)]
final class ResumeJsonLdGeneratorTest extends TestCase
{
    #[Test]
    public function generatesValidJsonLdScriptTag(): void
    {
        $output = ResumeJsonLdGenerator::generate('Alice', []);

        self::assertStringStartsWith('<script type="application/ld+json">', $output);
        self::assertStringEndsWith('</script>', $output);

        $json = $this->extractJson($output);
        self::assertNotEmpty($json);
    }

    #[Test]
    public function containsPersonType(): void
    {
        $output = ResumeJsonLdGenerator::generate('Alice', []);
        $json = $this->extractJson($output);

        self::assertArrayHasKey('@context', $json);
        self::assertSame('https://schema.org', $json['@context']);
        self::assertArrayHasKey('@type', $json);
        self::assertSame('Person', $json['@type']);
    }

    #[Test]
    public function includesName(): void
    {
        $output = ResumeJsonLdGenerator::generate('Alice Smith', []);
        $json = $this->extractJson($output);

        self::assertArrayHasKey('name', $json);
        self::assertSame('Alice Smith', $json['name']);
    }

    #[Test]
    public function includesOccupationFromExperienceData(): void
    {
        $output = ResumeJsonLdGenerator::generate('Bob', [
            'experience' => [
                ['title' => 'Software Engineer', 'location' => 'San Francisco'],
                ['title' => 'CTO', 'location' => 'New York'],
            ],
        ]);

        $json = $this->extractJson($output);

        self::assertArrayHasKey('hasOccupation', $json);
        $occupations = $json['hasOccupation'];
        self::assertIsArray($occupations);
        self::assertCount(2, $occupations);

        $first = $occupations[0];
        self::assertIsArray($first);
        self::assertSame('Occupation', $first['@type']);
        self::assertSame('Software Engineer', $first['name']);

        $location = $first['occupationLocation'];
        self::assertIsArray($location);
        self::assertSame('San Francisco', $location['name']);

        $second = $occupations[1];
        self::assertIsArray($second);
        self::assertSame('CTO', $second['name']);
    }

    #[Test]
    public function includesAlumniOfFromEducation(): void
    {
        $output = ResumeJsonLdGenerator::generate('Carol', [
            'education' => [
                ['institution' => 'MIT'],
                ['institution' => 'Stanford'],
            ],
        ]);

        $json = $this->extractJson($output);

        self::assertArrayHasKey('alumniOf', $json);
        $alumni = $json['alumniOf'];
        self::assertIsArray($alumni);
        self::assertCount(2, $alumni);

        $first = $alumni[0];
        self::assertIsArray($first);
        self::assertSame('EducationalOrganization', $first['@type']);
        self::assertSame('MIT', $first['name']);

        $second = $alumni[1];
        self::assertIsArray($second);
        self::assertSame('Stanford', $second['name']);
    }

    #[Test]
    public function includesEmailAndUrl(): void
    {
        $output = ResumeJsonLdGenerator::generate('Dave', [
            'email' => 'dave@example.com',
            'url' => 'https://dave.dev',
        ]);

        $json = $this->extractJson($output);

        self::assertArrayHasKey('email', $json);
        self::assertSame('dave@example.com', $json['email']);
        self::assertArrayHasKey('url', $json);
        self::assertSame('https://dave.dev', $json['url']);
    }

    #[Test]
    public function includesSkillsAsKnowsAbout(): void
    {
        $output = ResumeJsonLdGenerator::generate('Eve', [
            'skills' => [
                ['category' => 'Languages', 'items' => ['PHP', 'Go']],
                ['category' => 'Tools', 'items' => ['Docker']],
            ],
        ]);

        $json = $this->extractJson($output);

        self::assertArrayHasKey('knowsAbout', $json);
        $knowsAbout = $json['knowsAbout'];
        self::assertIsArray($knowsAbout);
        self::assertSame(['PHP', 'Go', 'Docker'], $knowsAbout);
    }

    #[Test]
    public function includesLanguagesAsKnowsLanguage(): void
    {
        $output = ResumeJsonLdGenerator::generate('Frank', [
            'languages' => [
                ['language' => 'English'],
                ['language' => 'French'],
            ],
        ]);

        $json = $this->extractJson($output);

        self::assertArrayHasKey('knowsLanguage', $json);
        $langs = $json['knowsLanguage'];
        self::assertIsArray($langs);
        self::assertSame(['English', 'French'], $langs);
    }

    #[Test]
    public function omitsEmptyFieldsFromOutput(): void
    {
        $output = ResumeJsonLdGenerator::generate('Grace', []);
        $json = $this->extractJson($output);

        self::assertArrayNotHasKey('email', $json);
        self::assertArrayNotHasKey('url', $json);
        self::assertArrayNotHasKey('hasOccupation', $json);
        self::assertArrayNotHasKey('alumniOf', $json);
        self::assertArrayNotHasKey('knowsAbout', $json);
        self::assertArrayNotHasKey('knowsLanguage', $json);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJson(string $scriptTag): array
    {
        $json = str_replace(
            ['<script type="application/ld+json">', '</script>'],
            '',
            $scriptTag,
        );

        /** @var array<string, mixed> */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
