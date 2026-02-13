<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Resume;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Resume\ResumePdfGenerator;

#[CoversClass(ResumePdfGenerator::class)]
final class ResumePdfGeneratorTest extends TestCase
{
    #[Test]
    public function generatesValidHtmlDocumentWithNameInTitle(): void
    {
        $html = ResumePdfGenerator::generatePrintHtml('John Doe', []);

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<title>John Doe', $html);
        self::assertStringContainsString('Resume</title>', $html);
        self::assertStringContainsString('<h1>John Doe</h1>', $html);
        self::assertStringContainsString('</html>', $html);
    }

    #[Test]
    public function includesExperienceSectionWhenProvided(): void
    {
        $html = ResumePdfGenerator::generatePrintHtml('Jane Smith', [
            'experience' => [
                [
                    'title' => 'Senior Developer',
                    'company' => 'Acme Corp',
                    'location' => 'New York',
                    'period' => '2020-2024',
                    'description' => 'Led development team',
                ],
            ],
        ]);

        self::assertStringContainsString('Experience', $html);
        self::assertStringContainsString('Senior Developer', $html);
        self::assertStringContainsString('Acme Corp', $html);
        self::assertStringContainsString('New York', $html);
        self::assertStringContainsString('2020-2024', $html);
        self::assertStringContainsString('Led development team', $html);
    }

    #[Test]
    public function includesEducationSectionWhenProvided(): void
    {
        $html = ResumePdfGenerator::generatePrintHtml('Jane Smith', [
            'education' => [
                [
                    'degree' => 'MSc Computer Science',
                    'institution' => 'MIT',
                    'year' => '2019',
                ],
            ],
        ]);

        self::assertStringContainsString('Education', $html);
        self::assertStringContainsString('MSc Computer Science', $html);
        self::assertStringContainsString('MIT', $html);
        self::assertStringContainsString('2019', $html);
    }

    #[Test]
    public function includesSummarySection(): void
    {
        $html = ResumePdfGenerator::generatePrintHtml('Bob', [
            'summary' => 'Experienced full-stack developer',
        ]);

        self::assertStringContainsString('Summary', $html);
        self::assertStringContainsString('Experienced full-stack developer', $html);
    }

    #[Test]
    public function includesSkillsSection(): void
    {
        $html = ResumePdfGenerator::generatePrintHtml('Bob', [
            'skills' => [
                ['category' => 'Languages', 'items' => ['PHP', 'TypeScript']],
                ['category' => 'Frameworks', 'items' => ['Pulsar', 'React']],
            ],
        ]);

        self::assertStringContainsString('Skills', $html);
        self::assertStringContainsString('Languages', $html);
        self::assertStringContainsString('PHP, TypeScript', $html);
    }

    #[Test]
    public function escapesUserContent(): void
    {
        $html = ResumePdfGenerator::generatePrintHtml('<script>alert("xss")</script>', [
            'summary' => 'A&B "quotes" <tag>',
        ]);

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('A&amp;B', $html);
        self::assertStringContainsString('&quot;quotes&quot;', $html);
    }

    #[Test]
    public function emptyResumeDataProducesMinimalValidHtml(): void
    {
        $html = ResumePdfGenerator::generatePrintHtml('Empty Resume', []);

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<h1>Empty Resume</h1>', $html);
        // No section headings should appear
        self::assertStringNotContainsString('Experience', $html);
        self::assertStringNotContainsString('Education', $html);
        self::assertStringNotContainsString('Skills', $html);
    }

    #[Test]
    public function includesLanguagesCertificationsAndProjects(): void
    {
        $html = ResumePdfGenerator::generatePrintHtml('Full', [
            'languages' => [
                ['language' => 'English', 'level' => 'Native'],
                ['language' => 'French', 'level' => 'B2'],
            ],
            'certifications' => [
                ['name' => 'AWS Solutions Architect', 'issuer' => 'Amazon', 'year' => '2023'],
            ],
            'projects' => [
                ['name' => 'Open Source Lib', 'description' => 'A useful library'],
            ],
        ]);

        self::assertStringContainsString('Languages', $html);
        self::assertStringContainsString('English', $html);
        self::assertStringContainsString('Native', $html);
        self::assertStringContainsString('Certifications', $html);
        self::assertStringContainsString('AWS Solutions Architect', $html);
        self::assertStringContainsString('Projects', $html);
        self::assertStringContainsString('Open Source Lib', $html);
    }

    #[Test]
    public function includesPrintMediaCss(): void
    {
        $html = ResumePdfGenerator::generatePrintHtml('Print', []);

        self::assertStringContainsString('@media print', $html);
        self::assertStringContainsString('page-break-inside', $html);
    }
}
